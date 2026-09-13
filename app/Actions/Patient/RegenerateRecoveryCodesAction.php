<?php

namespace App\Actions\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventType;
use App\Jobs\Patient\SendTwoFactorNoticeJob;
use App\Models\Patient;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\TwoFactor;
use Illuminate\Validation\ValidationException;

/**
 * Replace every recovery code. Requires a code from the authenticator itself —
 * NOT a recovery code: one leaked recovery code must not be a way to mint a
 * whole new set. (The lost-phone path is to set up a new authenticator with a
 * recovery code, which issues new codes on confirmation.)
 */
class RegenerateRecoveryCodesAction
{
    public const REFUSAL = 'Enter the current code from your authenticator app.';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly PatientSecurityLog $log,
    ) {}

    /**
     * @return list<string>
     *
     * @throws ValidationException keyed `code`
     */
    public function execute(Patient $patient, mixed $code, ?RequestContext $client = null): array
    {
        if (! $this->twoFactor->acceptCode($patient, $code)) {
            throw ValidationException::withMessages(['code' => self::REFUSAL]);
        }

        $codes = $this->twoFactor->replaceRecoveryCodes($patient);

        $this->log->record(SecurityEventType::RecoveryCodesRegenerated, patient: $patient, client: $client);

        SendTwoFactorNoticeJob::dispatch($patient->getKey(), SendTwoFactorNoticeJob::CODES_REGENERATED);

        return $codes;
    }
}
