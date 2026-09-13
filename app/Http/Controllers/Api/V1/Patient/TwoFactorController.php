<?php

namespace App\Http\Controllers\Api\V1\Patient;

use App\Actions\Exceptions\ActionException;
use App\Actions\Patient\ConfirmTwoFactorSetupAction;
use App\Actions\Patient\DisableTwoFactorAction;
use App\Actions\Patient\RegenerateRecoveryCodesAction;
use App\Actions\Patient\StartTwoFactorSetupAction;
use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\TwoFactorPolicy;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\PatientTrustedDevice;
use App\Services\Patient\TrustedDevices;
use App\Services\Patient\TwoFactor;
use App\Settings\PortalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in patient's own two-step verification: status, set up (or replace
 * the authenticator), confirm, new recovery codes, turn off.
 *
 * Reachable while the `required` policy confines an unenrolled session — these
 * are the routes it confines it TO.
 */
class TwoFactorController extends ApiController
{
    /**
     * Two-step verification status for this account.
     *
     * `setup_required` is true when the install requires it and the account
     * does not have it yet. `offered` is false only under the `off` policy for
     * an account without it.
     *
     * @tags PatientAuth
     */
    public function show(Request $request, TwoFactor $twoFactor, PortalSettings $settings, TrustedDevices $trustedDevices): JsonResponse
    {
        $patient = $request->user();
        $policy = $settings->twoFactorPolicy();
        $enabled = $patient->hasTwoFactor();

        return $this->success([
            'policy' => $policy->value,
            'enabled' => $enabled,
            'offered' => $enabled || $policy !== TwoFactorPolicy::Off,
            'setup_required' => $policy === TwoFactorPolicy::Required && ! $enabled,
            'can_disable' => $enabled && $policy !== TwoFactorPolicy::Required,
            'confirmed_at' => $patient->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_remaining' => $enabled ? $twoFactor->remainingRecoveryCodes($patient) : 0,
            'trusted_device_days' => $trustedDevices->days(),
        ]);
    }

    /**
     * Browsers trusted to skip the code on this account, newest first. Only live
     * ones (unrevoked, unexpired).
     *
     * @tags PatientAuth
     */
    public function trustedDevices(Request $request): JsonResponse
    {
        return $this->success(
            $request->user()->trustedDevices()->active()->latest('last_used_at')->get()
                ->map(fn (PatientTrustedDevice $device): array => [
                    'id' => $device->uuid,
                    'label' => $device->label,
                    'created_at' => $device->created_at?->toIso8601String(),
                    'last_used_at' => $device->last_used_at?->toIso8601String(),
                    'last_used_ip' => $device->last_used_ip,
                    'expires_at' => $device->expires_at->toIso8601String(),
                ])->all()
        );
    }

    /**
     * Stop trusting one browser. It will be asked for a code next time.
     *
     * @tags PatientAuth
     */
    public function revokeTrustedDevice(Request $request, string $device, TrustedDevices $trustedDevices): JsonResponse
    {
        if (! $trustedDevices->revokeOne($request->user(), $device, RequestContext::fromRequest($request))) {
            return $this->error('Not found.', 404);
        }

        return $this->success(['revoked' => true]);
    }

    /**
     * Stop trusting every browser on this account.
     *
     * @tags PatientAuth
     */
    public function revokeAllTrustedDevices(Request $request, TrustedDevices $trustedDevices): JsonResponse
    {
        return $this->success([
            'revoked' => $trustedDevices->revokeAll($request->user(), 'patient', RequestContext::fromRequest($request), SecurityEventActor::Patient),
        ]);
    }

    /**
     * Start setting up an authenticator app.
     *
     * Returns a new secret (for manual entry), its `otpauth://` URI, and a QR
     * code as an SVG data URI. Nothing changes until `confirm`. A first setup
     * requires `password`; replacing an existing authenticator requires `code` —
     * a current code or a recovery code.
     *
     * @tags PatientAuth
     */
    public function setup(Request $request, StartTwoFactorSetupAction $action): JsonResponse
    {
        $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $action->execute($request->user(), $request->input('code'), $request->input('password'));
        } catch (ActionException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }

        return $this->success([
            'secret' => $result['secret'],
            'otpauth_uri' => $result['otpauth_uri'],
            'qr_code' => $result['qr_code'],
            'expires_at' => $result['expires_at']->toIso8601String(),
        ]);
    }

    /**
     * Confirm setup with a code from the new authenticator.
     *
     * Turns two-step verification on and returns eight recovery codes. They are
     * shown ONCE — this is the only response that ever contains them.
     *
     * @tags PatientAuth
     */
    public function confirm(Request $request, ConfirmTwoFactorSetupAction $action): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $codes = $action->execute($request->user(), $request->input('code'), RequestContext::fromRequest($request));

        return $this->success(['enabled' => true, 'recovery_codes' => $codes]);
    }

    /**
     * Replace every recovery code. Requires a current authenticator code (not a
     * recovery code). The new codes are returned once.
     *
     * @tags PatientAuth
     */
    public function recoveryCodes(Request $request, RegenerateRecoveryCodesAction $action): JsonResponse
    {
        // 32, not 16: a pasted recovery code must reach the action and get its
        // sentence ("enter the code from your authenticator app"), not a length error.
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $codes = $action->execute($request->user(), $request->input('code'), RequestContext::fromRequest($request));

        return $this->success(['recovery_codes' => $codes]);
    }

    /**
     * Turn two-step verification off. Requires the password AND a current code
     * (authenticator or recovery). Signs out other sessions. 403 when the install
     * requires it.
     *
     * @tags PatientAuth
     */
    public function disable(Request $request, DisableTwoFactorAction $action): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $current = $request->user()->currentAccessToken();

        try {
            $action->execute(
                $request->user(),
                $request->input('password'),
                $request->input('code'),
                $current instanceof PersonalAccessToken ? $current->getKey() : null,
                RequestContext::fromRequest($request),
            );
        } catch (ActionException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }

        return $this->success(['enabled' => false]);
    }
}
