<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventType;
use App\Events\Patient\TwoFactorEnrolled;
use App\Jobs\Patient\SendTwoFactorNoticeJob;
use App\Models\Patient;
use App\Models\PatientAuthChallenge;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\TwoFactor;
use Illuminate\Validation\ValidationException;

/**
 * Prove the pending authenticator works, switch it on, and hand out recovery
 * codes — which are returned here once and exist nowhere else in plain text.
 *
 * The confirming code's time step is recorded as the last accepted one, so the
 * code just typed cannot be replayed as the first sign-in. Replacing an
 * authenticator also voids any sign-in challenge already waiting on the old one.
 */
class ConfirmTwoFactorSetupAction
{
    use Transacts;

    public const REFUSAL = 'That code didn’t match. Check the time on your phone is set automatically, then try the newest code.';

    public const EXPIRED = 'Setup took too long. Start again to get a new code.';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly PatientSecurityLog $log,
    ) {}

    /**
     * @return list<string> the recovery codes, in plain text, once
     *
     * @throws ValidationException keyed `code`
     */
    public function execute(Patient $patient, mixed $code, ?RequestContext $client = null): array
    {
        $pending = $patient->two_factor_pending_secret;

        if (blank($pending) || $patient->two_factor_pending_at === null
            || $patient->two_factor_pending_at->lte(now()->subMinutes(StartTwoFactorSetupAction::PENDING_TTL_MINUTES))) {
            throw ValidationException::withMessages(['code' => self::EXPIRED]);
        }

        $step = $this->twoFactor->matchingTimestep($pending, $code, null);

        if ($step === null) {
            throw ValidationException::withMessages(['code' => self::REFUSAL]);
        }

        $replacing = $patient->hasTwoFactor();

        $codes = $this->tx(function () use ($patient, $pending, $step): array {
            $patient->forceFill([
                'two_factor_secret' => $pending,
                'two_factor_confirmed_at' => now(),
                'two_factor_last_timestep' => $step,
                'two_factor_pending_secret' => null,
                'two_factor_pending_at' => null,
            ])->save();

            PatientAuthChallenge::voidOutstandingFor($patient);

            return $this->twoFactor->replaceRecoveryCodes($patient);
        });

        $this->log->record(
            SecurityEventType::TwoFactorEnrolled,
            patient: $patient,
            client: $client,
            context: ['method' => $replacing ? 'replaced' : 'new'],
        );

        TwoFactorEnrolled::dispatch($patient);
        SendTwoFactorNoticeJob::dispatch($patient->getKey(), $replacing ? SendTwoFactorNoticeJob::REPLACED : SendTwoFactorNoticeJob::ENABLED);

        return $codes;
    }
}
