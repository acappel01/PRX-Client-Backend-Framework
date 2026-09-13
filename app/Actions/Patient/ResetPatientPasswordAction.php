<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Events\Patient\EmailVerified;
use App\Events\Patient\PasswordChanged;
use App\Jobs\Patient\SendPasswordChangedNoticeJob;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Use a password-reset link: set the password, verify the address, sign every
 * session out.
 *
 * ── Bound to the account AND the address ────────────────────────────────────
 *
 * The token names the account it was minted for (`patient_id`) and the address
 * it went to. Both must still hold: an operator who changed the account's email
 * after the link went out has moved the account to a different mailbox, and the
 * old mailbox's link must stop working. No email is accepted from the request.
 *
 * ── Why a reset verifies, and takes over an unverified account ──────────────
 *
 * Holding the link proves the mailbox, which is exactly what `email_verified_at`
 * means. For an account that was never verified — registered by someone who
 * merely typed the address, before registration stopped creating rows — this is
 * how the real owner takes it back: new password, verified, every session the
 * squatter held revoked. A chart already on that account stays: an unverified
 * account can only hold one because an operator linked it by hand, and that
 * operator decision is not this flow's to undo. It is logged so it is visible.
 *
 * ── No sign-in ──────────────────────────────────────────────────────────────
 *
 * The link is the mailbox factor. Once two-factor sign-in exists it must not
 * stand in for the second factor, so this ends by sending the person to sign in
 * rather than minting a session.
 */
class ResetPatientPasswordAction
{
    use Transacts;

    /**
     * @throws ValidationException keyed `token`.
     */
    public function execute(mixed $plain, string $password, ?string $ip = null): Patient
    {
        if (! PatientEmailToken::looksValid($plain)) {
            throw $this->refusal();
        }

        $token = PatientEmailToken::query()
            ->where('token_hash', PatientEmailToken::hash($plain))
            ->where('purpose', PatientEmailToken::PURPOSE_PASSWORD_RESET)
            ->first();

        // `patient` excludes deleted accounts, so an operator's removal also
        // kills links already sent.
        $patient = $token?->patient;

        if ($token === null
            || ! $token->isUsable()
            || $patient === null
            || ! $token->sentToMatches($patient->email)) {
            throw $this->refusal();
        }

        $firstVerification = $patient->email_verified_at === null;

        $patient = $this->tx(function () use ($token, $patient, $password, $ip): Patient {
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

            $patient->forceFill([
                'password' => $password,
                'email_verified_at' => $patient->email_verified_at ?? now(),
            ])->save();

            // Every session, including any a squatter or a password thief holds.
            $patient->tokens()->delete();

            // Any other reset link still in an inbox is now a way back in.
            PatientEmailToken::query()
                ->where('patient_id', $patient->getKey())
                ->where('purpose', PatientEmailToken::PURPOSE_PASSWORD_RESET)
                ->outstanding()
                ->delete();

            return $patient;
        });

        if ($firstVerification && $patient->hasPrxChart()) {
            Log::warning('A password reset verified an account that already held a medical record.', [
                'patient_id' => $patient->getKey(),
            ]);
        }

        PasswordChanged::dispatch($patient);

        if ($firstVerification) {
            EmailVerified::dispatch($patient);
        }

        SendPasswordChangedNoticeJob::dispatch($patient->getKey());

        return $patient;
    }

    private function refusal(): ValidationException
    {
        return ValidationException::withMessages(['token' => ClaimPatientRecordAction::REFUSAL_ANONYMOUS]);
    }
}
