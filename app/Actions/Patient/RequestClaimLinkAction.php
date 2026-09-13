<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Actions\Exceptions\ActionException;
use App\Events\Patient\ClaimLinkRequested;
use App\Integrations\Messages\EmailMessage;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use App\Services\Patient\PatientMail;
use App\Settings\BrandSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email a single-use link that connects an order's medical record to the
 * signed-in account — to the address the ORDER was placed under.
 *
 * ── Why the mailbox, and why this closes the residual ──────────────────────
 *
 * Chart linking already takes the chart from the order's encounter, which no
 * caller can forge. What it could not prove is that the account holder is the
 * person who placed the order: registration does not verify an address, so
 * someone who knew a customer's email and held a leaked order uuid could claim
 * it. A link sent to the order's own mailbox is the proof that was missing —
 * holding the uuid is worth nothing if the link goes somewhere you cannot read.
 *
 * So the caller supplies NOTHING. No uuid, no address: the eligible order is
 * resolved from the session's own account, and the uuid stops travelling.
 *
 * ── Enumeration ────────────────────────────────────────────────────────────
 *
 * The controller answers the same 202 whether an order matched or not. A
 * different answer would tell anyone who registered a stranger's address
 * whether that stranger has a completed consultation. The one question asked
 * BEFORE eligibility is "can this install send at all", so a 503 describes the
 * installation and never the order.
 *
 * ── Why the system sends this, not a workflow ──────────────────────────────
 *
 * The message carries a live credential. As a workflow step the token would sit
 * in the workflow context, the queued job and the run log, and an operator could
 * route that step to a webhook or a vendor; switching the workflow off would
 * silently break linking. It still goes out through `CapabilityRouting`, so WHO
 * delivers it is the operator's choice, exactly as it is for `send_email`. The
 * token-free `ClaimLinkRequested` event is where configurable follow-ups hang.
 *
 * Sent synchronously, so the plain token never lands in a queue payload, and so
 * a failed send is reported rather than promised.
 */
class RequestClaimLinkAction
{
    use Transacts;

    public function __construct(
        private readonly PatientMail $mail,
        private readonly BrandSettings $brand,
    ) {}

    /**
     * @return bool Whether a link was sent. For the caller's tests and logs
     *              only — never let it change what the visitor is told.
     *
     * @throws ActionException 503 when this installation cannot send.
     */
    public function execute(Patient $patient, ?string $ip = null): bool
    {
        $delivery = $this->mail->deliveryOrFail('Claim link');

        $lead = $this->eligibleLead($patient);

        if ($lead === null) {
            return false;
        }

        $plain = PatientEmailToken::newPlainToken();

        $token = $this->tx(function () use ($patient, $lead, $plain, $ip): PatientEmailToken {
            // One live link per order. A second request supersedes the first,
            // so an inbox holding several copies holds one that works.
            PatientEmailToken::query()
                ->where('lead_id', $lead->getKey())
                ->where('purpose', PatientEmailToken::PURPOSE_CLAIM)
                ->outstanding()
                ->delete();

            return PatientEmailToken::create([
                'purpose' => PatientEmailToken::PURPOSE_CLAIM,
                'patient_id' => $patient->getKey(),
                'lead_id' => $lead->getKey(),
                'sent_to' => Str::lower($lead->email),
                'token_hash' => PatientEmailToken::hash($plain),
                'expires_at' => now()->addMinutes(PatientEmailToken::CLAIM_TTL_MINUTES),
                'requested_ip' => $ip,
            ]);
        });

        try {
            $this->mail->send($delivery, $this->message($patient, $lead, $plain));
        } catch (Throwable $e) {
            // A link nobody received must not stay live. Logged with ids only:
            // the address is PII and the exception may quote the recipient.
            $token->delete();

            Log::error('Claim link failed to send.', [
                'patient_id' => $patient->getKey(),
                'lead_uuid' => $lead->uuid,
                'integration' => $delivery[0]->slug,
                'exception' => $e::class,
            ]);

            throw $this->mail->unavailable();
        }

        ClaimLinkRequested::dispatch($patient);

        return true;
    }

    /**
     * The newest order under this account's address that has real evidence
     * behind it and nobody has claimed.
     *
     * The encounter requirement is the same rule `LinkPatientToPrxChartAction`
     * enforces, applied here too so a self-minted lead never earns an email:
     * `POST /leads` is anonymous, and mailing a link for an order that can
     * never be claimed would only be a way to make us send mail to anyone.
     */
    private function eligibleLead(Patient $patient): ?Lead
    {
        return Lead::query()
            ->claimableUnder($patient->email)
            ->latest('id')
            ->first();
    }

    /**
     * Plain text, deliberately minimal.
     *
     * NOTHING CLINICAL, AND NOTHING THAT NAMES WHAT WAS ORDERED. A lock screen
     * or a shared inbox shows the subject and first line to whoever is looking,
     * and the brand itself already says "telehealth". So: no product, no
     * consultation language, no order uuid, no chart id, and no address in the
     * URL. The account's creation date is included so a person who did not
     * create that account can tell the link is not meant for them.
     *
     * 🔴 AND NOTHING THE ACCOUNT HOLDER WROTE. This message goes to the ORDER's
     * mailbox, and the account asking may be exactly the stranger this flow
     * exists to stop — registration is anonymous and verifies nothing. A
     * greeting built from the account's `first_name` would let them put 100
     * characters of their own text ("your card was declined, call…") into an
     * email from the brand's own verified domain. So no name, in the body or
     * the To: display name. Every value below is settings, a date, or composed
     * by this server.
     */
    private function message(Patient $patient, Lead $lead, string $plain): EmailMessage
    {
        $brand = $this->brand->name;
        $created = $patient->created_at->format('F j, Y');
        $ttl = PatientEmailToken::CLAIM_TTL_MINUTES;

        $body = <<<TEXT
            Hi,

            Use the link below to connect your {$brand} order to the account you created on {$created}.

            {$this->url($plain)}

            The link works once and expires in {$ttl} minutes. You will need to be signed in to that account when you open it.

            If you did not ask for this, you can ignore this email. Nothing changes unless the link is used.
            TEXT;

        return new EmailMessage(
            to: $lead->email,
            subject: "{$brand}: connect your account",
            body: $body,
            trackLinks: false,
        );
    }

    private function url(string $plain): string
    {
        return $this->mail->portalLink('claim_path', $plain);
    }
}
