<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
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
 * "Email me a link" for someone who is not signed in — behind both
 * `POST /patient/auth/register` and `POST /patient/auth/password/forgot`.
 *
 * ── No account row without a proven mailbox ─────────────────────────────────
 *
 * Registration used to create a Patient from a typed address. `patients.email`
 * is unique, so anyone could register a customer's address first and lock the
 * real customer out, and nothing about that account was ever proven. Now
 * nothing is created here. What happens depends only on what already exists
 * under the address, and the answer is the same whichever it is:
 *
 * | Under the address                | Sent                                   |
 * |----------------------------------|----------------------------------------|
 * | an account (verified or not)     | a password_reset link                  |
 * | a deleted account                | nothing — an operator removed it       |
 * | no account, a claimable order    | a create_account link for that order   |
 * | neither                          | nothing                                |
 *
 * Why an account of EITHER state gets a reset link: the mailbox owner resetting
 * an unverified account is how a pre-existing squatted row stops blocking the
 * real owner — the reset verifies the address and signs every other session
 * out (ResetPatientPasswordAction). And "register" with an address that already
 * has an account is exactly "forgot my password", so the two routes share this.
 *
 * ── Runs in a queued job, never in the request ──────────────────────────────
 *
 * The endpoints are anonymous, so a response that took longer when an account
 * or order existed would answer the question the uniform 202 refuses to. The
 * admin runs PHP as an Apache module, where nothing can run after the response
 * is flushed, so the whole decision runs in SendAccountLinkJob. The token is
 * minted HERE, inside the worker: the queue payload carries only the address
 * (encrypted) and never a credential.
 *
 * ── Nothing anyone typed reaches the mailbox ────────────────────────────────
 *
 * The link goes out before anything is proven. A name from the account (maybe a
 * squatter's) or from the order would let a stranger write into an email from
 * the brand's verified domain. Every value in these messages is settings, a
 * constant, or composed by this server.
 */
class RequestAccountLinkAction
{
    use Transacts;

    public const SENT_RESET = 'password_reset';

    public const SENT_CREATE = 'create_account';

    public function __construct(
        private readonly PatientMail $mail,
        private readonly BrandSettings $brand,
    ) {}

    /**
     * @return string|null Which link went out, or null. For tests and logs only.
     */
    public function execute(string $email, ?string $ip = null): ?string
    {
        $email = self::normalise($email);

        $delivery = rescue(fn () => $this->mail->deliveryOrFail('Account link'), null, false);

        if ($delivery === null) {
            // The request already answered 202 after the same check passed; the
            // installation changed in between. Logged by deliveryOrFail.
            return null;
        }

        $patient = Patient::withTrashed()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($patient !== null) {
            if ($patient->trashed()) {
                return null;
            }

            return $this->sendReset($delivery, $patient, $ip);
        }

        $lead = Lead::query()->claimableUnder($email)->latest('id')->first();

        return $lead === null ? null : $this->sendCreate($delivery, $lead, $email, $ip);
    }

    /** Trim and lowercase — the only canonical form. Gmail dots and +tags are kept: other providers treat them as different mailboxes. */
    public static function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function sendReset(array $delivery, Patient $patient, ?string $ip): ?string
    {
        $plain = PatientEmailToken::newPlainToken();
        $sentTo = self::normalise($patient->email);

        $token = $this->mint($plain, [
            'purpose' => PatientEmailToken::PURPOSE_PASSWORD_RESET,
            'patient_id' => $patient->getKey(),
            'sent_to' => $sentTo,
            'expires_at' => now()->addMinutes(PatientEmailToken::PASSWORD_RESET_TTL_MINUTES),
            'requested_ip' => $ip,
        ]);

        $brand = $this->brand->name;
        $ttl = PatientEmailToken::PASSWORD_RESET_TTL_MINUTES;
        $url = $this->mail->portalLink('reset_path', $plain);

        $body = <<<TEXT
            Hi,

            We received a request to set a new password for your {$brand} account.

            {$url}

            The link works once and expires in {$ttl} minutes. Setting a new password signs you out everywhere else.

            If you did not ask for this, you can ignore this email. Your password stays the same unless the link is used.
            TEXT;

        return $this->deliver($delivery, $token, new EmailMessage(
            to: $sentTo,
            subject: "{$brand}: reset your password",
            body: $body,
            trackLinks: false,
        ), self::SENT_RESET);
    }

    private function sendCreate(array $delivery, Lead $lead, string $email, ?string $ip): ?string
    {
        $plain = PatientEmailToken::newPlainToken();

        $token = $this->mint($plain, [
            'purpose' => PatientEmailToken::PURPOSE_CREATE_ACCOUNT,
            'lead_id' => $lead->getKey(),
            'sent_to' => $email,
            'expires_at' => now()->addMinutes(PatientEmailToken::CREATE_ACCOUNT_TTL_MINUTES),
            'requested_ip' => $ip,
        ]);

        $brand = $this->brand->name;
        $ttl = PatientEmailToken::CREATE_ACCOUNT_TTL_MINUTES;
        $url = $this->mail->portalLink('create_account_path', $plain);

        $body = <<<TEXT
            Hi,

            Use the link below to create your {$brand} account.

            {$url}

            The link works once and expires in {$ttl} minutes.

            If you did not ask for this, you can ignore this email. No account is created unless the link is used.
            TEXT;

        return $this->deliver($delivery, $token, new EmailMessage(
            to: $email,
            subject: "{$brand}: create your account",
            body: $body,
            trackLinks: false,
        ), self::SENT_CREATE);
    }

    /**
     * One live link per purpose per address: a new request supersedes the
     * outstanding one, so an inbox holding several copies holds one that works.
     */
    private function mint(string $plain, array $attributes): PatientEmailToken
    {
        return $this->tx(function () use ($plain, $attributes): PatientEmailToken {
            PatientEmailToken::query()
                ->where('purpose', $attributes['purpose'])
                ->where('sent_to', $attributes['sent_to'])
                ->outstanding()
                ->delete();

            return PatientEmailToken::create($attributes + [
                'token_hash' => PatientEmailToken::hash($plain),
            ]);
        });
    }

    private function deliver(array $delivery, PatientEmailToken $token, EmailMessage $message, string $sent): ?string
    {
        try {
            $this->mail->send($delivery, $message);
        } catch (Throwable $e) {
            // A link nobody received must not stay live. Ids only: the address
            // is PII and the exception may quote the recipient.
            $token->delete();

            Log::error('Account link failed to send.', [
                'purpose' => $token->purpose,
                'patient_id' => $token->patient_id,
                'lead_id' => $token->lead_id,
                'integration' => $delivery[0]->slug,
                'exception' => $e::class,
            ]);

            return null;
        }

        return $sent;
    }
}
