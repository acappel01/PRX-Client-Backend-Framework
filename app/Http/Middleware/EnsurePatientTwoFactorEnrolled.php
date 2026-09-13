<?php

namespace App\Http\Middleware;

use App\Enums\Patient\TwoFactorPolicy;
use App\Models\Patient;
use App\Settings\PortalSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Under the `required` two-step policy, a signed-in patient without it is
 * confined to setting it up.
 *
 * Judged on every request from the policy and the account, never stamped onto
 * the token: so switching the policy on confines sessions already signed in on
 * their next request, without signing anyone out, and finishing setup releases
 * the same session at once. (Token abilities could not do this — nothing here
 * enforces them, and they cannot change after issue.)
 *
 * The 403 carries a machine-readable `code` the portal routes on.
 */
class EnsurePatientTwoFactorEnrolled
{
    public const CODE = 'two_factor_setup_required';

    public function __construct(private readonly PortalSettings $settings) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $patient = $request->user();

        if ($patient instanceof Patient
            && $this->settings->twoFactorPolicy() === TwoFactorPolicy::Required
            && ! $patient->hasTwoFactor()) {
            return response()->json([
                'message' => 'Set up two-step verification to continue.',
                'code' => self::CODE,
            ], 403);
        }

        return $next($request);
    }
}
