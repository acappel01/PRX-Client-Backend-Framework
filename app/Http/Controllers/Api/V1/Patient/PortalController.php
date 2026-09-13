<?php

namespace App\Http\Controllers\Api\V1\Patient;

use App\Actions\Patient\IssuePortalTokenAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Patient;
use App\Services\Patient\PatientActionStackService;
use App\Services\Patient\PortalResponseFilter;
use App\Services\PrescribeRx\Client;
use App\Services\PrescribeRx\Exceptions\PrescribeRxException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Patient portal — proxies PRX /me/patient/* endpoints.
 *
 * Patient-token calls go through withPatientToken() which handles automatic
 * 401 recovery (evict cache → re-mint → retry once). Scheduling endpoints
 * use the sales-org token directly and bypass the patient-token flow.
 *
 * All routes require `auth:sanctum` + `patient` middleware (set in api.php),
 * plus `no-store` — every response here is one patient's PHI.
 *
 * TWO RULES GOVERN THIS CLASS.
 *
 * 1. **Nothing leaves without passing PortalResponseFilter.** PRX dumps raw
 *    Eloquent models on two of these endpoints, so "return what PRX returned"
 *    ships a live video credential and the order's profit margin to a browser.
 *
 * 2. **No id from the request body is ever forwarded.** Every identifier we
 *    send to PRX is either read from the session's own Patient record or
 *    proven to belong to it first. (`storeVital` does forward the request body
 *    wholesale, but it carries no id: PRX takes the chart from the token and
 *    discards unknown keys. Anything that grows an id there must be validated
 *    here first.) This matters most on the two SCHEDULING
 *    endpoints, which authenticate with the SALES-ORG token: PRX gates those
 *    on the *caller's* tenancy, and the caller is the whole organisation, so
 *    PRX's own ownership check cannot tell one of our patients from another.
 *    Forwarding a body straight through there is not a leak, it is a
 *    cross-patient WRITE. The pin has to happen on this side.
 */
class PortalController extends ApiController
{
    public function __construct(
        private readonly Client $prx,
        private readonly IssuePortalTokenAction $tokenAction,
        private readonly PortalResponseFilter $filter,
        private readonly PatientActionStackService $actionStack,
    ) {}

    /**
     * The portal home screen, composed and ranked server-side.
     *
     * SCREEN-SHAPED ON PURPOSE. The portal makes ONE call here rather than six,
     * and the reason is not tidiness. The obvious way to make a clinical portal
     * fast is to cache the reads — but a cache of clinical reads IS storing PHI,
     * with a retention policy nobody wrote and a revocation path nobody built.
     * That lever is unavailable to us, so the one we do have is fan-out
     * reduction: compose the screen here, close to the provider, instead of
     * making a phone on a bad connection do six sequential round-trips.
     *
     * The RANKING is also here, and that is architectural rather than
     * incidental — see PatientActionStackService. The portal renders a ranked
     * list; it never decides what is urgent.
     *
     * @tags Patient Portal
     */
    public function home(Request $request): JsonResponse
    {
        /** @var Patient $patient */
        $patient = $request->user();

        // An unlinked account is a VALID portal account with zero clinical
        // visibility — it is the normal state between signing up and
        // completing intake, not an error. It gets a real, calm screen.
        if (! $patient->hasPrxChart()) {
            return $this->success([
                'greeting' => $this->greeting($patient),
                'tasks' => [],
                'linked' => false,
            ]);
        }

        [$dashboard, $encounters] = $this->withPatientToken($patient, fn ($t) => [
            $this->prx->getPatientDashboard($t),
            $this->prx->getPatientEncounters($t, ['per_page' => 25]),
        ]);

        return $this->success([
            'greeting' => $this->greeting($patient),
            'linked' => true,
            'tasks' => $this->actionStack->build($encounters, $dashboard),
            // The filtered dashboard rides along so Home can show progress
            // without a second call. Same allowlist as the standalone endpoint.
            'summary' => $this->filter->apply('dashboard', $dashboard),
        ]);
    }

    private function greeting(Patient $patient): string
    {
        $name = trim((string) $patient->first_name);

        return $name === '' ? 'Your care' : "Good day, {$name}";
    }

    /**
     * Patient portal dashboard summary.
     *
     * @tags Patient Portal
     */
    public function dashboard(Request $request): JsonResponse
    {
        $patient = $request->user();

        return $this->success(
            $this->filter->apply('dashboard', $this->withPatientToken($patient, fn ($t) => $this->prx->getPatientDashboard($t)))
        );
    }

    /**
     * List the patient's encounters.
     *
     * @tags Patient Portal
     */
    public function encounters(Request $request): JsonResponse
    {
        $patient = $request->user();
        $query = $request->only(['page', 'per_page', 'status']);

        return $this->success(
            $this->filter->apply('encounters', $this->withPatientToken($patient, fn ($t) => $this->prx->getPatientEncounters($t, $query)))
        );
    }

    /**
     * Retrieve a video-room token for an encounter.
     *
     * PRX requires the patient token (not the sales-org token) for video room
     * access so the PHI audit log attributes the session to the patient.
     *
     * @tags Patient Portal
     */
    public function videoToken(Request $request, string $encounterId): JsonResponse
    {
        $patient = $request->user();

        return $this->success(
            $this->filter->apply('video-token', $this->withPatientToken($patient, fn ($t) => $this->prx->getEncounterVideoToken($t, $encounterId)))
        );
    }

    /**
     * List the patient's vitals.
     *
     * @tags Patient Portal
     */
    public function vitals(Request $request): JsonResponse
    {
        $patient = $request->user();

        return $this->success(
            $this->filter->apply('vitals', $this->withPatientToken($patient, fn ($t) => $this->prx->getPatientVitals($t)))
        );
    }

    /**
     * Record a new vital entry.
     *
     * @tags Patient Portal
     */
    public function storeVital(Request $request): JsonResponse
    {
        $patient = $request->user();
        $data = $request->all();

        return $this->success(
            $this->filter->apply('vitals', $this->withPatientToken($patient, fn ($t) => $this->prx->recordPatientVital($t, $data))),
            status: 201
        );
    }

    /**
     * List the patient's orders.
     *
     * @tags Patient Portal
     */
    public function orders(Request $request): JsonResponse
    {
        $patient = $request->user();

        return $this->success(
            $this->filter->apply('orders', $this->withPatientToken($patient, fn ($t) => $this->prx->getPatientOrders($t)))
        );
    }

    /**
     * List the patient's prescriptions.
     *
     * @tags Patient Portal
     */
    public function prescriptions(Request $request): JsonResponse
    {
        $patient = $request->user();

        return $this->success(
            $this->filter->apply('prescriptions', $this->withPatientToken($patient, fn ($t) => $this->prx->getPatientPrescriptions($t)))
        );
    }

    /**
     * List the patient's conversations.
     *
     * @tags Patient Portal
     */
    public function conversations(Request $request): JsonResponse
    {
        $patient = $request->user();

        return $this->success(
            $this->filter->apply('conversations', $this->withPatientToken($patient, fn ($t) => $this->prx->getPatientConversations($t)))
        );
    }

    /**
     * List messages in a conversation.
     *
     * @tags Patient Portal
     */
    public function conversationMessages(Request $request, string $conversationId): JsonResponse
    {
        // Checked here so a malformed cursor never reaches the provider, whose
        // 422 carries no field errors and would render as "check your values".
        $validated = $request->validate([
            'after' => ['sometimes', 'bail', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Str::isUuid($value) && strtotime((string) $value) === false) {
                    $fail('The after cursor must be a message id or an ISO-8601 time.');
                }
            }],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ]);
        $patient = $request->user();
        $query = array_filter([
            'after' => $validated['after'] ?? null,
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : null,
        ], fn ($value) => $value !== null);

        return $this->success(
            $this->filter->apply(
                isset($query['after']) ? 'messages-poll' : 'messages',
                $this->withPatientToken($patient, fn ($t) => $this->prx->getConversationMessages($t, $conversationId, $query))
            )
        );
    }

    /**
     * Open (or reuse) the conversation with the provider for one of the patient's
     * visits. The provider only finds encounters on the token's own chart, so a
     * foreign id is a 404.
     *
     * @tags Patient Portal
     */
    public function openConversation(Request $request, string $encounterId): JsonResponse
    {
        $patient = $request->user();

        return $this->success(
            $this->filter->apply('conversation-opened', $this->withPatientToken(
                $patient,
                fn ($t) => $this->prx->openEncounterConversation($t, $encounterId)
            )),
            status: 201
        );
    }

    /**
     * Send a message in a conversation.
     *
     * @tags Patient Portal
     */
    public function sendMessage(Request $request, string $conversationId): JsonResponse
    {
        // max:5000 mirrors PRX's own rule. Ours was 10000, so a 5001-character
        // message passed here and 422'd at PRX — the validation error surfaced
        // one hop away from the field that caused it.
        $validated = $request->validate(['content' => ['required', 'string', 'max:5000']]);
        $patient = $request->user();
        $content = $validated['content'];

        return $this->success(
            $this->filter->apply('message', $this->withPatientToken(
                $patient,
                fn ($t) => $this->prx->sendConversationMessage($t, $conversationId, $content)
            )),
            status: 201
        );
    }

    /**
     * List available scheduling slots.
     *
     * Scheduling uses the sales-org token, NOT the patient token, and that is
     * deliberate: PRX resolves the eligible provider pool from the *token's*
     * organisation, and a patient token carries none, which would widen the pool
     * to every licensed provider rather than the org's own.
     *
     * The price of that choice is that PRX cannot tell which of our patients is
     * asking, so `patient_chart_id` is injected from the session here and the
     * caller's own value is never read. It is a required PRX field and we were
     * not sending it at all, so this endpoint returned 422 on every call.
     *
     * @tags Patient Portal
     */
    public function availabilitySlots(Request $request): JsonResponse
    {
        /** @var Patient $patient */
        $patient = $request->user();

        $this->assertLinkedChart($patient);

        $validated = $request->validate([
            'encounter_type_id' => ['required', 'uuid'],
            // Mirrors PRX's own rule, so a past date fails at the field that
            // caused it rather than as a 422 one hop away.
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        return $this->success($this->filter->apply('slots', $this->prx->getAvailabilitySlots([
            ...array_filter($validated, fn ($v) => $v !== null),
            'patient_chart_id' => $patient->prx_patient_chart_id,
        ])));
    }

    /**
     * Book an appointment.
     *
     * THIS IS THE MOST DANGEROUS ENDPOINT IN THE PROXY, and it used to forward
     * `$request->except('_token')` wholesale under the sales-org token.
     *
     * PRX validates `encounter_id` with `exists:encounters,id` and then gates it
     * with `ApiEncounterAccess::canAccess($encounter, $request->user())`
     * (prx-demo@07969f8, Scheduling/AppointmentController.php:101,117-126). That
     * gate is correct for PRX and useless for us: `$request->user()` is our
     * ORGANISATION, so it returns true for every encounter in the org — every
     * other patient's included. Booking then runs ScheduleEncounterAction
     * against it (:183-189), writing `scheduled_at`, assigning the caller's
     * chosen provider and provisioning a video room. On the victim's encounter.
     *
     * So `encounter_id` is proven to belong to the session's own chart HERE,
     * using the patient token — whose global scope PRX pins to the chart — before
     * the org token is allowed anywhere near it.
     *
     * `client_id` and `sales_organization_id` are accepted by PRX and flow into
     * ApiTenantResolver, letting a caller stamp any organisation onto the row.
     * They are never forwarded. Nor are `provider_timezone` (an internal display
     * concern) or `duration_minutes` (the encounter type's to decide).
     *
     * @tags Patient Portal
     */
    public function bookAppointment(Request $request): JsonResponse
    {
        /** @var Patient $patient */
        $patient = $request->user();

        $this->assertLinkedChart($patient);

        $validated = $request->validate([
            'encounter_type_id' => ['required', 'uuid'],
            'provider_profile_id' => ['required', 'uuid'],
            'scheduled_start' => ['required', 'date', 'after:now'],
            'patient_timezone' => ['nullable', 'string', 'timezone'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'encounter_id' => ['nullable', 'uuid'],
        ]);

        if (! empty($validated['encounter_id'])) {
            $owned = $this->withPatientToken(
                $patient,
                fn ($t) => $this->prx->findPatientEncounter($t, $validated['encounter_id'])
            );

            // Compare the chart id rather than settling for "the probe returned
            // something". `extractData` yields [] for a 2xx with no `data`, which
            // `=== null` would accept, and PRX resolves "the user's chart" as the
            // first patient_charts row for that user — a patient user re-linked
            // by email across organisations could hold more than one.
            $ownedChart = $owned['patient_chart_id'] ?? null;

            if ($ownedChart === null || (string) $ownedChart !== (string) $patient->prx_patient_chart_id) {
                // Deliberately the same shape as PRX's own refusal, and
                // deliberately not "no such encounter" — whether an id exists is
                // not something an unauthorised caller should learn.
                return $this->error('You do not have access to the specified encounter.', 403);
            }
        }

        $payload = [
            ...array_filter($validated, fn ($v) => $v !== null),
            'patient_chart_id' => $patient->prx_patient_chart_id,
        ];

        return $this->success(
            $this->filter->apply('appointment', $this->prx->createAppointment($payload)),
            status: 201
        );
    }

    /**
     * Refuse early when the session has no linked PRX chart.
     *
     * An unlinked account is a valid portal account with zero clinical
     * visibility, so this is an expected state, not an error condition — but
     * every scheduling call needs a chart id to pin, and a null one would be
     * omitted by array_filter and leave the caller's value unopposed.
     */
    private function assertLinkedChart(Patient $patient): void
    {
        if (! $patient->hasPrxChart()) {
            abort(response()->json(
                ['message' => 'No clinical record is linked to this account yet.'],
                409
            ));
        }
    }

    /**
     * Run a PRX call that requires a patient token, with automatic 401 recovery.
     *
     * On 401: evicts the cached token, re-mints via issue-token, retries once.
     * If the re-mint call itself fails (chart deleted / org access revoked), propagates.
     *
     * @param  callable(string $token): mixed  $call
     */
    private function withPatientToken(Patient $patient, callable $call): mixed
    {
        try {
            return $call($this->tokenAction->execute($patient));
        } catch (PrescribeRxException $e) {
            if ($e->httpStatus !== 401) {
                throw $e;
            }

            return $call($this->tokenAction->renew($patient));
        }
    }
}
