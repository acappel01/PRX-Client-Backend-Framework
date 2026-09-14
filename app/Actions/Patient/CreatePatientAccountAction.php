<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Actions\Customers\LinkCustomerToClaimedLeadAction;
use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventType;
use App\Events\Patient\AccountCreated;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\RecordClaimed;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\PatientSessionLifetime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Use a create-account link: create the account, verify its address and connect
 * its order's record — all or none of it.
 *
 * ── The address comes from the token, never the request ────────────────────
 *
 * `email` is the token's `sent_to`: the mailbox the link was delivered to, which
 * is what the click proves. No email field is accepted, so no request can create
 * an account for an address it did not receive mail at. `email_verified_at` is
 * stamped for the same reason.
 *
 * No session takes part, so there is no confused deputy: the only person who can
 * finish this holds the mailbox, and someone requesting a link for a stranger's
 * address produces the stranger's own account and nothing for themselves.
 *
 * ── One account per address ────────────────────────────────────────────────
 *
 * If any account holds the address by the time the link is used — created
 * through another link, restored by an operator, or deleted but still holding the
 * unique email — the answer is the ordinary refusal. Asking again then sends a
 * password-reset link (RequestAccountLinkAction), which is the right door. The
 * unique index is the backstop for two links racing.
 *
 * ── Race safety ─────────────────────────────────────────────────────────────
 *
 * Same shape as ClaimPatientRecordAction: non-locking lookup, consumption by a
 * conditional UPDATE on the primary key, and linking in the same transaction, so
 * a refusal from the link (no chart yet, order claimed meanwhile) rolls the
 * account and the consumption back and the link still works once fixed.
 */
class CreatePatientAccountAction
{
    use Transacts;

    public function __construct(
        private readonly LinkPatientToPrxChartAction $link,
        private readonly PatientSecurityLog $log,
        private readonly PatientSessionLifetime $lifetime,
    ) {}

    /**
     * @return array{patient: Patient, token: string}
     *
     * @throws ValidationException keyed `token`.
     */
    public function execute(mixed $plain, string $password, string $deviceName = 'api', ?RequestContext $client = null): array
    {
        if (! PatientEmailToken::looksValid($plain)) {
            throw $this->refusal();
        }

        $token = PatientEmailToken::query()
            ->where('token_hash', PatientEmailToken::hash($plain))
            ->where('purpose', PatientEmailToken::PURPOSE_CREATE_ACCOUNT)
            ->first();

        if ($token === null
            || ! $token->isUsable()
            || $token->lead === null
            || $token->lead->patient_id !== null) {
            throw $this->refusal();
        }

        try {
            $patient = $this->tx(function () use ($token, $password, $client): Patient {
                $won = PatientEmailToken::query()
                    ->whereKey($token->getKey())
                    ->whereNull('consumed_at')
                    ->where('expires_at', '>', now())
                    ->update([
                        'consumed_at' => now(),
                        'consumed_ip' => $client?->ip,
                    ]);

                if ($won !== 1) {
                    throw $this->refusal();
                }

                if (Patient::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($token->sent_to)])->exists()) {
                    throw $this->refusal();
                }

                $lead = $token->lead;

                $patient = new Patient;
                $patient->forceFill([
                    'email' => $token->sent_to,
                    'password' => $password,
                    // From the order the mailbox owner placed. Stored on the
                    // row only — it never goes into an email (see
                    // RequestAccountLinkAction).
                    'first_name' => (string) $lead->first_name,
                    'last_name' => (string) $lead->last_name,
                    'email_verified_at' => now(),
                ])->save();

                $linked = $this->link->execute($patient, $lead);
                app(LinkCustomerToClaimedLeadAction::class)->execute($linked, $lead);

                PatientEmailToken::query()
                    ->whereKey($token->getKey())
                    ->update(['consumed_by_patient_id' => $linked->getKey()]);

                return $linked;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->refusal();
        } catch (ValidationException $e) {
            throw $this->rekeyed($e);
        }

        $session = $patient->createToken($deviceName, ['patient:*'], $this->lifetime->expiresAt());

        // No separate `email_verified`: an account created by the link is
        // verified by being created.
        $this->log->record(SecurityEventType::AccountCreated, patient: $patient, client: $client, tokenId: $session->accessToken->getKey());
        $this->log->record(SecurityEventType::RecordClaimed, patient: $patient, client: $client);

        AccountCreated::dispatch($patient);
        RecordClaimed::dispatch($patient);
        EmailVerified::dispatch($patient);

        return ['patient' => $patient, 'token' => $session->plainTextToken];
    }

    private function refusal(): ValidationException
    {
        return ValidationException::withMessages(['token' => ClaimPatientRecordAction::REFUSAL_ANONYMOUS]);
    }

    /** The link action's sentences are written for the patient; only the key moves. */
    private function rekeyed(ValidationException $e): ValidationException
    {
        $errors = $e->errors();

        if (array_key_exists('token', $errors)) {
            return $e;
        }

        return ValidationException::withMessages([
            'token' => collect($errors)->flatten()->first() ?? ClaimPatientRecordAction::REFUSAL_ANONYMOUS,
        ]);
    }
}
