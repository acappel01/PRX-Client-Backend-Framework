<?php

namespace App\Http\Controllers\Api\V1\Patient;

use App\Actions\Patient\ListPatientSecurityEventsAction;
use App\Data\Patient\PatientSecurityEventResource;
use App\Http\Controllers\Api\V1\ApiController;
use App\Settings\PortalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in patient's own account security history.
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
}
