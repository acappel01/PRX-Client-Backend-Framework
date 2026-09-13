<?php

namespace App\Actions\Patient;

use App\Actions\Exceptions\ActionException;
use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientAuthChallenge;
use App\Models\PatientEmailToken;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\PatientSessionLifetime;
use App\Services\Patient\TrustedDevices;
use App\Services\Patient\TwoFactor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The second half of a two-step sign-in: a challenge and a code in, a session
 * out.
 *
 * ── One sentence for every refusal ─────────────────────────────────────────
 *
 * Unknown, expired, spent or burned challenge, wrong code: the same 422, so a
 * caller learns nothing about which. The only other answer is a 429, and it is
 * reachable only by someone who already knows the password.
 *
 * ── Counting, then checking ─────────────────────────────────────────────────
 *
 * An attempt is claimed by a conditional UPDATE (`attempts < MAX`) BEFORE the
 * code is looked at, so parallel guesses cannot exceed the limit. The last
 * allowed failure voids the challenge: the password must be entered again.
 * On top of that, a per-patient limiter (10 failures / 15 minutes) bounds
 * guessing across many challenges. It cannot be used to lock a victim out:
 * reaching it needs their password.
 *
 * ── The session starts here ─────────────────────────────────────────────────
 *
 * The token is minted only after the challenge is consumed, with the normal
 * session lifetime and `two_factor_verified_at` set — so the idle and absolute
 * clocks start at the second factor, and a later step-up check has a time to
 * read.
 */
class CompleteTwoFactorLoginAction
{
    public const REFUSAL = 'That code didn’t work. Check the code and try again, or sign in again.';

    public const TOO_MANY = 'Too many attempts. Wait a few minutes, then sign in again.';

    public const PATIENT_LIMIT = 10;

    public const PATIENT_LIMIT_DECAY_SECONDS = 900;

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly PatientSecurityLog $log,
        private readonly PatientSessionLifetime $lifetime,
        private readonly TrustedDevices $trustedDevices,
    ) {}

    /**
     * @return array{patient: Patient, token: string, trusted_device: array{token: string, expires_at: CarbonInterface}|null}
     *
     * @throws ValidationException keyed `code`
     * @throws ActionException 429
     */
    public function execute(mixed $plainChallenge, mixed $code, mixed $recoveryCode, ?RequestContext $client = null, bool $trustDevice = false): array
    {
        if (! PatientEmailToken::looksValid($plainChallenge)) {
            throw $this->refusal();
        }

        $challenge = PatientAuthChallenge::query()
            ->where('token_hash', PatientEmailToken::hash($plainChallenge))
            ->where('purpose', PatientAuthChallenge::PURPOSE_LOGIN)
            ->first();

        $patient = $challenge?->patient;

        if ($challenge === null || $patient === null || ! $challenge->isUsable() || ! $patient->hasTwoFactor()) {
            throw $this->refusal();
        }

        $limiterKey = 'two-factor:'.$patient->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, self::PATIENT_LIMIT)) {
            throw ActionException::failed(self::TOO_MANY, 429);
        }

        $claimed = PatientAuthChallenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('consumed_at')
            ->whereNull('voided_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', PatientAuthChallenge::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($claimed !== 1) {
            throw $this->refusal();
        }

        $method = match (true) {
            filled($recoveryCode) => $this->twoFactor->acceptRecoveryCode($patient, $recoveryCode) ? 'recovery_code' : null,
            default => $this->twoFactor->acceptCode($patient, $code) ? 'totp' : null,
        };

        if ($method === null) {
            $this->failed($challenge, $patient, $limiterKey, $client);
        }

        $consumed = PatientAuthChallenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('consumed_at')
            ->whereNull('voided_at')
            ->update(['consumed_at' => now()]);

        if ($consumed !== 1) {
            throw $this->refusal();
        }

        $session = $patient->createToken($challenge->device_name ?: 'api', ['patient:*'], $this->lifetime->expiresAt());
        $session->accessToken->forceFill(['two_factor_verified_at' => now()])->save();

        $this->log->record(
            SecurityEventType::LoginSucceeded,
            patient: $patient,
            client: $client,
            tokenId: $session->accessToken->getKey(),
            context: ['method' => $method],
        );

        if ($method === 'recovery_code') {
            $this->log->record(
                SecurityEventType::RecoveryCodeUsed,
                patient: $patient,
                client: $client,
                tokenId: $session->accessToken->getKey(),
                context: ['remaining' => $this->twoFactor->remainingRecoveryCodes($patient)],
            );
        }

        // Only after a real second factor — a trusted browser never trusts itself.
        $trusted = $trustDevice ? $this->trustedDevices->trust($patient, $client) : null;

        return ['patient' => $patient, 'token' => $session->plainTextToken, 'trusted_device' => $trusted];
    }

    private function failed(PatientAuthChallenge $challenge, Patient $patient, string $limiterKey, ?RequestContext $client): never
    {
        RateLimiter::hit($limiterKey, self::PATIENT_LIMIT_DECAY_SECONDS);

        $attempts = (int) PatientAuthChallenge::query()->whereKey($challenge->getKey())->value('attempts');
        $locked = $attempts >= PatientAuthChallenge::MAX_ATTEMPTS;

        if ($locked) {
            PatientAuthChallenge::query()->whereKey($challenge->getKey())->whereNull('voided_at')->update(['voided_at' => now()]);
        }

        $this->log->record(
            SecurityEventType::TwoFactorChallengeFailed,
            patient: $patient,
            client: $client,
            actor: SecurityEventActor::Anonymous,
            context: ['remaining_attempts' => max(0, PatientAuthChallenge::MAX_ATTEMPTS - $attempts), 'locked' => $locked],
        );

        throw $this->refusal();
    }

    private function refusal(): ValidationException
    {
        return ValidationException::withMessages(['code' => self::REFUSAL]);
    }
}
