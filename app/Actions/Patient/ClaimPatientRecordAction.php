<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\RecordClaimed;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use Illuminate\Validation\ValidationException;

/**
 * Consume a claim link: prove the mailbox, link the record, verify the address
 * — all or none of it.
 *
 * ── The binding, and the confused deputy it prevents ────────────────────────
 *
 * The token is bound to the ORDER and the ADDRESS it was sent to, and the claim
 * requires a signed-in session whose address equals that address. It is NOT
 * bound to whoever requested it (`patient_id` on the row is audit only).
 *
 * The variant that must never be built is "consume the link and attach the
 * record to whichever account holds that address" with no session: an attacker
 * who registered a victim's address could request a link, and the victim —
 * clicking an email that genuinely came from us — would attach their own
 * clinical record to the attacker's account. Requiring the clicker's own
 * session means the victim's click can only ever land on an account the
 * victim can sign in to.
 *
 * ── Race safety on MySQL REPEATABLE-READ ────────────────────────────────────
 *
 * The lookup is a plain, non-locking read. Consumption is a conditional UPDATE
 * by PRIMARY KEY (`consumed_at IS NULL AND expires_at > now`), and exactly one
 * affected row is the proof this request won. A primary-key equality on an
 * existing row takes a record lock only — no gap lock, so this cannot repeat
 * the deadlock `LinkPatientToPrxChartAction` documents. Lock order is always
 * token, then lead.
 *
 * Linking runs inside the same transaction. If it refuses — the record is
 * already held elsewhere, the provider has not returned a chart yet — the
 * consumption rolls back with it, and the link still works once the problem is
 * fixed rather than being burned by a failure that was not the patient's.
 * A single-threaded suite cannot race two requests, so the conditional UPDATE is
 * pinned by the second-use test and by reasoning, not by a concurrency test.
 */
class ClaimPatientRecordAction
{
    use Transacts;

    /** One sentence for every token failure, so none can be told apart. */
    public const REFUSAL = 'This link is invalid or has expired. Request a new one from your account.';

    /**
     * The same, for the links used without a session (create-account, reset),
     * where "your account" is not somewhere the reader can go.
     */
    public const REFUSAL_ANONYMOUS = 'This link is invalid or has expired. Request a new one from the sign-in page.';

    public function __construct(private readonly LinkPatientToPrxChartAction $link) {}

    /**
     * @param  int|null  $currentTokenId  The Sanctum token this request arrived
     *                                    on, kept when other sessions are revoked.
     *
     * @throws ValidationException keyed `token`.
     */
    public function execute(Patient $patient, mixed $plain, ?int $currentTokenId = null, ?string $ip = null): Patient
    {
        if (! PatientEmailToken::looksValid($plain)) {
            throw $this->refusal();
        }

        $token = PatientEmailToken::query()
            ->where('token_hash', PatientEmailToken::hash($plain))
            ->where('purpose', PatientEmailToken::PURPOSE_CLAIM)
            ->first();

        // Unknown, spent, expired, sent to someone else, or its order is gone:
        // one refusal. Checked before any write, so a mismatched session never
        // consumes a link meant for somebody else.
        if ($token === null
            || ! $token->isUsable()
            || ! $token->sentToMatches($patient->email)
            || $token->lead === null) {
            throw $this->refusal();
        }

        $firstVerification = $patient->email_verified_at === null;

        try {
            $patient = $this->tx(function () use ($patient, $token, $ip): Patient {
                $won = PatientEmailToken::query()
                    ->whereKey($token->getKey())
                    ->whereNull('consumed_at')
                    ->where('expires_at', '>', now())
                    ->update([
                        'consumed_at' => now(),
                        'consumed_by_patient_id' => $patient->getKey(),
                        'consumed_ip' => $ip,
                    ]);

                if ($won !== 1) {
                    throw $this->refusal();
                }

                $linked = $this->link->execute($patient, $token->lead);

                // Stamped ONLY after the link succeeded and ONLY because the
                // session's address equals the one this message went to — which
                // is the whole of what "verified" means here.
                if ($linked->email_verified_at === null) {
                    $linked->forceFill(['email_verified_at' => now()])->save();
                }

                return $linked;
            });
        } catch (ValidationException $e) {
            throw $this->rekeyed($e);
        }

        if ($firstVerification) {
            // Sessions opened before the address was proven may belong to
            // someone who knew the password without holding the mailbox. They
            // go; the session that just proved it stays.
            $patient->tokens()
                ->when($currentTokenId !== null, fn ($q) => $q->whereKeyNot($currentTokenId))
                ->delete();
        }

        RecordClaimed::dispatch($patient);

        if ($firstVerification) {
            EmailVerified::dispatch($patient);
        }

        return $patient;
    }

    private function refusal(): ValidationException
    {
        return ValidationException::withMessages(['token' => self::REFUSAL]);
    }

    /**
     * The link action names its field `lead_uuid`, which this endpoint does not
     * take. Its sentences are already written for the patient, so they are
     * kept; only the key moves.
     */
    private function rekeyed(ValidationException $e): ValidationException
    {
        return ValidationException::withMessages([
            'token' => collect($e->errors())->flatten()->first() ?? self::REFUSAL,
        ]);
    }
}
