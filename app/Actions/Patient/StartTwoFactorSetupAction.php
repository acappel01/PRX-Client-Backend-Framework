<?php

namespace App\Actions\Patient;

use App\Actions\Exceptions\ActionException;
use App\Enums\Patient\TwoFactorPolicy;
use App\Models\Patient;
use App\Services\Patient\TwoFactor;
use App\Settings\PortalSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Begin setting up (or replacing) an authenticator: a new secret, held as
 * PENDING until a code from it is confirmed.
 *
 * A confirmed secret is never touched here, so abandoning setup leaves the
 * account exactly as it was. Replacing an authenticator requires a current code
 * — a TOTP code or a recovery code, which is the lost-phone path — so a stolen
 * session cannot swap the second factor for its own.
 *
 * A FIRST setup requires the password. Without it, a session left open on
 * someone else's device could enrol that person's phone and lock the owner out —
 * and a password reset deliberately does not remove two-step verification, so
 * the owner would need support to get back in.
 *
 * Not offered under the `off` policy, unless the account already has it on:
 * an owner who chose it keeps the ability to manage it.
 */
class StartTwoFactorSetupAction
{
    public const PENDING_TTL_MINUTES = 15;

    public const NOT_OFFERED = 'Two-step verification isn’t available for this account.';

    public const CODE_REQUIRED = 'Enter a code from your current authenticator app, or a recovery code.';

    public const PASSWORD_REQUIRED = 'Enter your password to set up two-step verification.';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly PortalSettings $settings,
    ) {}

    /**
     * @return array{secret: string, otpauth_uri: string, qr_code: string, expires_at: CarbonInterface}
     *
     * @throws ActionException 403 when not offered
     * @throws ValidationException keyed `password` on a first setup without the right password,
     *                             or `code` when replacing without a valid current code
     */
    public function execute(Patient $patient, mixed $currentCode = null, mixed $password = null): array
    {
        if ($patient->hasTwoFactor()) {
            if ($this->twoFactor->acceptEither($patient, $currentCode) === null) {
                throw ValidationException::withMessages(['code' => self::CODE_REQUIRED]);
            }
        } elseif ($this->settings->twoFactorPolicy() === TwoFactorPolicy::Off) {
            throw ActionException::failed(self::NOT_OFFERED, 403);
        } elseif (! is_string($password) || ! Hash::check($password, $patient->password)) {
            throw ValidationException::withMessages(['password' => self::PASSWORD_REQUIRED]);
        }

        $secret = $this->twoFactor->newSecret();

        $patient->forceFill([
            'two_factor_pending_secret' => $secret,
            'two_factor_pending_at' => now(),
        ])->save();

        $uri = $this->twoFactor->otpauthUri($patient, $secret);

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_code' => $this->twoFactor->qrCodeDataUri($uri),
            'expires_at' => now()->addMinutes(self::PENDING_TTL_MINUTES),
        ];
    }
}
