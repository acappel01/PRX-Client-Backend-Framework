<?php

namespace App\Http\Controllers\Api\V1\Patient;

use App\Actions\Patient\ListPatientSecurityEventsAction;
use App\Data\Patient\PatientSecurityEventResource;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Patient\PatientSessionLifetime;
use App\Settings\PortalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in patient's own session and account security history.
 */
class SecurityController extends ApiController
{
    /**
     * Recent security events on the patient's account: sign-ins and failed
     * attempts (with IP address and browser), sign-outs, sessions ended, links
     * sent, password and account changes. Newest first.
     *
     * `is_current_session` marks the sign-in this request's session came from.
     * `actor` is `you`, `unverified` (a failed attempt or an anonymous link
     * request — not proven to be the patient), `operator` or `system`.
     * `meta.retention_days` is how long history is kept.
     *
     * @tags PatientAuth
     */
    public function events(Request $request, ListPatientSecurityEventsAction $action, PortalSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ListPatientSecurityEventsAction::MAX_LIMIT],
        ]);

        $current = $request->user()->currentAccessToken();
        $currentTokenId = $current instanceof PersonalAccessToken ? (int) $current->getKey() : null;

        $events = $action->execute($request->user(), (int) ($validated['limit'] ?? 20));

        return $this->success(
            $events->map(fn ($event) => PatientSecurityEventResource::fromModel($event, $currentTokenId)->toArray())->all(),
            ['retention_days' => $settings->security_events_retention_days],
        );
    }

    /**
     * The current session's deadlines.
     *
     * `idle_expires_at` is when the session ends if it is not used again;
     * `expires_at` is when it ends regardless. Calling this IS a use — it moves
     * `idle_expires_at` forward — which is what makes it the portal's "stay
     * signed in" call. It sits in the patient group's `api` limiter, not the
     * sign-in limiter, so a keep-alive cannot lock anyone out of signing in.
     *
     * @tags PatientAuth
     */
    public function session(Request $request, PatientSessionLifetime $lifetime): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        if (! $current instanceof PersonalAccessToken) {
            return $this->error('Not a session token.', 401);
        }

        $deadlines = $lifetime->deadlines($current->refresh());

        return $this->success([
            'idle_minutes' => $lifetime->idleMinutes(),
            'idle_expires_at' => $deadlines['idle_expires_at']->toIso8601String(),
            'expires_at' => $deadlines['expires_at']->toIso8601String(),
        ]);
    }
}
