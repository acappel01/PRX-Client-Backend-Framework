<?php

namespace App\Actions\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientAuthChallenge;
use App\Models\PatientEmailToken;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\PatientSessionLifetime;
use App\Services\Patient\TrustedDevices;
use Carbon\CarbonInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

/**
 * Check a patient's password and open a session — recording the attempt either
 * way.
 *
 * ── One answer, one cost ────────────────────────────────────────────────────
 *
 * An unknown address and a wrong password throw the same exception. They also
 * cost the same: an address with no account still spends one bcrypt, because
 * skipping it answered in microseconds where a real account took hundreds of
 * milliseconds, which told anyone timing the request which addresses had
 * accounts. Both branches also write one event through the same call.
 *
 * ── Failures for addresses with no account are recorded too ───────────────
 *
 * With no patient to attach to, the event carries only a keyed hash of the
 * address, so repeated attempts against one address — or from one IP across
 * many — can be seen without storing what was typed. `context.reason` says
 * which failure it was; it is internal and never returned to the caller.
 *
 * ── Two-step verification ───────────────────────────────────────────────────
 *
 * For an account with it on, a correct password opens NO session. It creates a
 * short-lived challenge (PatientAuthChallenge) and returns only that; the
 * session is minted by CompleteTwoFactorLoginAction once a code is given. That
 * branch is reached only past a correct password, so it tells a caller nothing a
 * correct password does not; the unknown-address and wrong-password answers are
 * unchanged whether or not the account has two-step verification.
 */
class LoginPatientAction
{
    public function __construct(
        private readonly PatientSecurityLog $log,
        private readonly PatientSessionLifetime $lifetime,
        private readonly TrustedDevices $trustedDevices,
    ) {}

    /**
     * @return array{patient: Patient, token: string, trusted_device_expires_at: CarbonInterface|null}|array{patient: Patient, challenge: string, expires_at: CarbonInterface}
     *
     * @throws AuthenticationException
     */
    public function execute(string $email, string $password, string $deviceName = 'api', ?RequestContext $client = null, mixed $trustedDeviceToken = null): array
    {
        $patient = Patient::where('email', $email)->first();

        if ($patient === null) {
            // Spend the work a real check would. The result is discarded.
            Hash::make($password);

            $this->failed(null, $email, $client, 'unknown_account');
        }

        if (! Hash::check($password, $patient->password)) {
            $this->failed($patient, $email, $client, 'bad_password');
        }

        $method = null;
        $trusted = null;

        if ($patient->hasTwoFactor()) {
            // A browser the patient trusted after an earlier code skips the code
            // — only ever after the password above has been checked.
            $trusted = $this->trustedDevices->recognise($patient, $trustedDeviceToken, $client);

            if ($trusted === null) {
                return $this->challenge($patient, $deviceName, $client);
            }

            $method = 'trusted_device';
        }

        // `patient:*`, the same as every other patient session. It was `['*']`,
        // which nothing checks today (EnsurePatientToken tests the model type),
        // but a token that claims every ability is one refactor from meaning it.
        $session = $patient->createToken($deviceName, ['patient:*'], $this->lifetime->expiresAt());

        $this->log->record(
            SecurityEventType::LoginSucceeded,
            patient: $patient,
            client: $client,
            tokenId: $session->accessToken->getKey(),
            context: $method === null ? [] : ['method' => $method],
        );

        // The renewed expiry goes back to the client, so the browser's copy of the
        // trust lives as long as the admin's does — otherwise the cookie would
        // lapse at the ORIGINAL expiry however often it was used.
        return ['patient' => $patient, 'token' => $session->plainTextToken, 'trusted_device_expires_at' => $trusted?->expires_at];
    }

    /**
     * @return array{patient: Patient, challenge: string, expires_at: CarbonInterface}
     */
    private function challenge(Patient $patient, string $deviceName, ?RequestContext $client): array
    {
        $plain = PatientEmailToken::newPlainToken();

        $challenge = PatientAuthChallenge::create([
            'patient_id' => $patient->getKey(),
            'purpose' => PatientAuthChallenge::PURPOSE_LOGIN,
            'token_hash' => PatientEmailToken::hash($plain),
            'expires_at' => now()->addMinutes(PatientAuthChallenge::TTL_MINUTES),
            'device_name' => mb_substr($deviceName, 0, 255),
            'requested_ip' => $client?->ip,
            'user_agent' => $client?->userAgent,
        ]);

        // Unverified: the password was right, but nobody has proven the second
        // factor. For the account holder this is the earliest sign that someone
        // else knows their password.
        $this->log->record(
            SecurityEventType::TwoFactorChallenged,
            patient: $patient,
            client: $client,
            actor: SecurityEventActor::Anonymous,
        );

        return ['patient' => $patient, 'challenge' => $plain, 'expires_at' => $challenge->expires_at];
    }

    /**
     * @throws AuthenticationException always
     */
    private function failed(?Patient $patient, string $email, ?RequestContext $client, string $reason): never
    {
        $this->log->record(
            SecurityEventType::LoginFailed,
            patient: $patient,
            client: $client,
            actor: SecurityEventActor::Anonymous,
            email: $email,
            context: ['reason' => $reason],
        );

        throw new AuthenticationException('The provided credentials are incorrect.');
    }
}
