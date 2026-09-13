<?php

namespace App\Actions\Patient;

use App\Actions\Concerns\Transacts;
use App\Actions\Exceptions\ActionException;
use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventType;
use App\Enums\Patient\TwoFactorPolicy;
use App\Events\Patient\TwoFactorRemoved;
use App\Jobs\Patient\SendTwoFactorNoticeJob;
use App\Models\Patient;
use App\Models\PatientAuthChallenge;
use App\Services\Patient\PatientSecurityLog;
use App\Services\Patient\TwoFactor;
use App\Settings\PortalSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The patient turns two-step verification off. Needs BOTH the password and a
 * current code (TOTP or recovery), so neither a stolen session nor a stolen
 * password alone can remove it. Other sessions are signed out; this one stays.
 *
 * Refused under the `required` policy.
 */
class DisableTwoFactorAction
{
    use Transacts;

    public const REFUSAL = 'Those details didn’t match. Enter your password and a current code.';

    public const REQUIRED = 'Two-step verification is required for this account and can’t be turned off.';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly PatientSecurityLog $log,
        private readonly PortalSettings $settings,
    ) {}

    /**
     * @throws ActionException 403 under the required policy
     * @throws ValidationException keyed `code`
     */
    public function execute(Patient $patient, mixed $password, mixed $code, ?int $currentTokenId = null, ?RequestContext $client = null): void
    {
        if ($this->settings->twoFactorPolicy() === TwoFactorPolicy::Required) {
            throw ActionException::failed(self::REQUIRED, 403);
        }

        if (! $patient->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => self::REFUSAL]);
        }

        // Both are checked every time, in this order, so the answer does not say
        // which one was wrong.
        $passwordOk = is_string($password) && Hash::check($password, $patient->password);
        $method = $passwordOk ? $this->twoFactor->acceptEither($patient, $code) : null;

        if (! $passwordOk || $method === null) {
            throw ValidationException::withMessages(['code' => self::REFUSAL]);
        }

        $revoked = $this->tx(function () use ($patient, $currentTokenId): int {
            self::clear($patient);

            return $patient->tokens()
                ->when($currentTokenId !== null, fn ($q) => $q->whereKeyNot($currentTokenId))
                ->delete();
        });

        $this->log->record(SecurityEventType::TwoFactorRemoved, patient: $patient, client: $client, tokenId: $currentTokenId, context: ['method' => $method]);

        if ($revoked > 0) {
            $this->log->record(SecurityEventType::SessionsRevoked, patient: $patient, client: $client, tokenId: $currentTokenId, context: ['reason' => 'two_factor_removed', 'revoked' => $revoked]);
        }

        TwoFactorRemoved::dispatch($patient);
        SendTwoFactorNoticeJob::dispatch($patient->getKey(), SendTwoFactorNoticeJob::DISABLED);
    }

    /** Remove every trace of two-step verification from an account. Caller owns the transaction. */
    public static function clear(Patient $patient): void
    {
        $patient->forceFill([
            'two_factor_secret' => null,
            'two_factor_pending_secret' => null,
            'two_factor_pending_at' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        $patient->recoveryCodes()->delete();

        PatientAuthChallenge::voidOutstandingFor($patient);
    }
}
