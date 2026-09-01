<?php

namespace App\Services\PrescribeRx;

use App\Data\PrescribeRx\EncounterTypeData;
use App\Data\PrescribeRx\EncounterTypeSchemaData;
use App\Data\PrescribeRx\UnifiedIntakeRequestData;
use App\Data\PrescribeRx\UnifiedIntakeResponseData;
use App\Services\PrescribeRx\Exceptions\PrescribeRxException;
use App\Settings\IntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP client for the prescribe-rx telehealth unified API.
 *
 * Reads connection details from:
 *   - `config/prescribe-rx.php` (sandbox/prod URLs, timeouts, retries)
 *   - `App\Settings\IntegrationSettings` (per-tenant: enabled flag,
 *      environment selection, encrypted API token, optional org IDs)
 *
 * All public methods return typed DTOs and throw `PrescribeRxException`
 * on any failure (not configured, connection failure, non-2xx, validation
 * error). Action-layer callers translate those into toasts.
 *
 * Stub mode: when `config('prescribe-rx.stub')` is true, returns canned
 * fixtures without hitting the network. Useful for local dev before a
 * sales-org token is issued.
 */
class Client
{
    public function __construct(
        protected IntegrationSettings $settings,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────

    /**
     * `GET /telehealth/encounter-types`
     *
     * Lists active encounter types available for intake.
     *
     * @return array<int, EncounterTypeData>
     */
    public function listEncounterTypes(?string $telehealthCompanyId = null): array
    {
        if (config('prescribe-rx.stub')) {
            return [
                EncounterTypeData::from([
                    'id' => '019ce396-46a1-73ab-87d6-c40310555401',
                    'name' => 'GLP-1 Screening',
                    'slug' => 'glp1-screening',
                    'description' => 'GLP-1 weight management screening',
                    'icon' => 'ti-stethoscope',
                    'product_class' => 'Weight Management',
                    'product_type' => 'GLP-1 Agonist',
                    'is_featured' => true,
                    'requires_labs' => false,
                    'interaction_type' => 'Asynchronous',
                ]),
            ];
        }

        $query = $telehealthCompanyId ? ['telehealth_company_id' => $telehealthCompanyId] : [];

        $payload = $this->extractData(
            $this->request()->get('/telehealth/encounter-types', $query)
        );

        return EncounterTypeData::collect($payload, 'array');
    }

    /**
     * `GET /telehealth/encounter-types/{id}/schema`
     *
     * Returns the full intake schema for one encounter type — drives the
     * dynamic wizard. Use the resulting `field_slugs` as the keys for
     * the `answers` payload on `submitUnifiedIntake()`.
     */
    public function getEncounterTypeSchema(string $encounterTypeId): EncounterTypeSchemaData
    {
        if (config('prescribe-rx.stub')) {
            return EncounterTypeSchemaData::from([
                'encounter_type' => [
                    'id' => $encounterTypeId,
                    'name' => 'GLP-1 Screening',
                    'slug' => 'glp1-screening',
                ],
                'steps' => [
                    [
                        'step_name' => 'Eligibility',
                        'step_description' => 'Quick screening',
                        'step_type' => 'screening',
                        'display_order' => 1,
                        'is_required' => true,
                        'fields' => [
                            [
                                'slug' => 'glp1_diabetes_mellitus',
                                'label' => 'Have you been diagnosed with Type 2 Diabetes?',
                                'field_type' => 'radio',
                                'is_required' => true,
                                'options' => [
                                    ['value' => 'Yes', 'label' => 'Yes'],
                                    ['value' => 'No', 'label' => 'No'],
                                ],
                            ],
                        ],
                    ],
                ],
                'field_slugs' => ['glp1_diabetes_mellitus'],
                'required_slugs' => ['glp1_diabetes_mellitus'],
                'meta' => ['total_steps' => 1, 'total_fields' => 1],
            ]);
        }

        $payload = $this->extractData(
            $this->request()->get("/telehealth/encounter-types/{$encounterTypeId}/schema")
        );

        return EncounterTypeSchemaData::from($payload);
    }

    /**
     * `POST /patients/{chart_id}/issue-token`
     *
     * Mint a short-lived (30-min) patient-scoped Sanctum token. Uses the
     * sales-org token to authenticate; returned token is capped to patient
     * abilities only (PHI-audit-logged on the PRX side).
     *
     * Pass the returned string to any `patientRequest()` call below.
     * Cache it in Redis (TTL ~25 min) to avoid minting on every request.
     *
     * @param  string[]  $abilities  Subset of patient abilities. Omit for full patient set.
     */
    /**
     * Mint a patient-scoped token via POST /patients/{id}/issue-token.
     *
     * Returns the full `data` payload including `token`, `expires_at`, and
     * `abilities`. Callers should use `expires_at` to derive the Redis TTL
     * rather than assuming a fixed window.
     *
     * Confirmed field names from IssuedPatientTokenResource:
     *   token, token_type, expires_at (ISO-8601), abilities[], patient_id, patient_chart_id
     *
     * @param  string[]  $abilities  Explicit least-privilege set. Omit to get the full patient set.
     * @return array{token: string, expires_at: string, abilities: list<string>, patient_id: string, patient_chart_id: string}
     */
    public function issuePatientToken(string $patientChartId, array $abilities = []): array
    {
        if (config('prescribe-rx.stub')) {
            return [
                'token' => 'stub-patient-token-'.$patientChartId,
                'token_type' => 'Bearer',
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
                'abilities' => $abilities ?: ['patient:read'],
                'patient_id' => 'stub-patient-id',
                'patient_chart_id' => $patientChartId,
            ];
        }

        $body = array_filter(['abilities' => $abilities ?: null, 'token_name' => 'portal-session']);

        $response = $this->request()->post("/patients/{$patientChartId}/issue-token", $body);
        $data = $this->extractData($response);

        if (empty($data['token'])) {
            throw new PrescribeRxException('issue-token response missing token field.', 422);
        }

        return $data;
    }

    // ─── Patient Portal (requires per-patient token from issuePatientToken) ─

    /**
     * `GET /me/patient/dashboard`
     *
     * Aggregated payload: latest vitals, active encounters, recent orders,
     * and goals. Ideal single call for the patient portal home screen.
     *
     * @return array<string, mixed>
     */
    public function getPatientDashboard(string $patientToken): array
    {
        if (config('prescribe-rx.stub')) {
            return ['encounters' => [], 'vitals' => [], 'orders' => []];
        }

        return $this->extractData($this->patientRequest($patientToken)->get('/me/patient/dashboard'));
    }

    /**
     * `GET /me/patient/encounters`
     *
     * All encounters for the authenticated patient, newest first.
     *
     * @param  array<string, mixed>  $query  e.g. ['per_page' => 10, 'page' => 1]
     * @return array<string, mixed>
     */
    public function getPatientEncounters(string $patientToken, array $query = []): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => [], 'total' => 0];
        }

        return $this->extractData($this->patientRequest($patientToken)->get('/me/patient/encounters', $query));
    }

    /**
     * `GET /encounters/{id}/video-token`
     *
     * Returns a Twilio AccessToken + room name for joining the encounter's
     * video call. Must be called with a patient token (not the sales-org
     * token) so the room is attributed to the patient's identity in the
     * PRX audit log.
     *
     * interaction_type must be 1 (VIDEO) — callers should check before calling.
     *
     * @return array{token: string, room_name: string, provider: string, expires_at: string}
     */
    public function getEncounterVideoToken(string $patientToken, string $encounterId): array
    {
        if (config('prescribe-rx.stub')) {
            return [
                'token' => 'stub-twilio-token',
                'room_name' => "encounter-{$encounterId}",
                'provider' => 'twilio',
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ];
        }

        return $this->extractData(
            $this->patientRequest($patientToken)->get("/encounters/{$encounterId}/video-token")
        );
    }

    /**
     * `GET /me/patient/vitals`
     *
     * Paginated vitals history for the authenticated patient.
     *
     * @return array<string, mixed>
     */
    public function getPatientVitals(string $patientToken, array $query = []): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => [], 'total' => 0];
        }

        return $this->extractData($this->patientRequest($patientToken)->get('/me/patient/vitals', $query));
    }

    /**
     * `POST /me/patient/vitals`
     *
     * Record a generic vital. Use type-specific endpoints for weight
     * (`/vitals/weight`), blood pressure (`/vitals/blood-pressure`),
     * and glucose (`/vitals/glucose`) when stricter validation is needed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordPatientVital(string $patientToken, array $data): array
    {
        if (config('prescribe-rx.stub')) {
            return array_merge(['id' => 'stub-vital-id'], $data);
        }

        return $this->extractData($this->patientRequest($patientToken)->post('/me/patient/vitals', $data));
    }

    /**
     * `GET /me/patient/orders`
     *
     * Order history for the authenticated patient.
     *
     * @return array<string, mixed>
     */
    public function getPatientOrders(string $patientToken, array $query = []): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => [], 'total' => 0];
        }

        return $this->extractData($this->patientRequest($patientToken)->get('/me/patient/orders', $query));
    }

    /**
     * `GET /me/patient/prescriptions`
     *
     * Active and historical prescriptions for the authenticated patient.
     *
     * @return array<string, mixed>
     */
    public function getPatientPrescriptions(string $patientToken): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => [], 'total' => 0];
        }

        return $this->extractData($this->patientRequest($patientToken)->get('/me/patient/prescriptions'));
    }

    /**
     * `GET /me/patient/conversations`
     * `GET /me/patient/conversations/{id}/messages`
     * `POST /me/patient/conversations/{id}/messages`
     *
     * Patient-provider messaging within encounter context.
     *
     * @return array<string, mixed>
     */
    public function getPatientConversations(string $patientToken): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => []];
        }

        return $this->extractData($this->patientRequest($patientToken)->get('/me/patient/conversations'));
    }

    public function getConversationMessages(string $patientToken, string $conversationId): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => []];
        }

        return $this->extractData(
            $this->patientRequest($patientToken)->get("/me/patient/conversations/{$conversationId}/messages")
        );
    }

    public function sendConversationMessage(string $patientToken, string $conversationId, string $body): array
    {
        if (config('prescribe-rx.stub')) {
            return ['id' => 'stub-msg-id', 'body' => $body];
        }

        return $this->extractData(
            $this->patientRequest($patientToken)->post("/me/patient/conversations/{$conversationId}/messages", [
                'body' => $body,
            ])
        );
    }

    // ─── Encounter Status (sales-org token) ──────────────────────────────────

    /**
     * `GET /telehealth/encounters/{id}/status`
     *
     * Lightweight status-only poll. Prefer subscribing to `encounter.*`
     * webhooks (`POST /webhooks`) to avoid polling entirely.
     *
     * @return array{status: string, status_label: string, interaction_type: int}
     */
    public function getEncounterStatus(string $encounterId): array
    {
        if (config('prescribe-rx.stub')) {
            return ['status' => 'unassigned', 'status_label' => 'Unassigned', 'interaction_type' => 4];
        }

        return $this->extractData(
            $this->request()->get("/telehealth/encounters/{$encounterId}/status")
        );
    }

    // ─── Catalog (sales-org token) ───────────────────────────────────────────

    /**
     * `GET /products`
     *
     * All products available to this sales org, paginated.
     * Each item: id, sku, name, short_description, description, pricing{retail_price,consumer_price}, image_url, is_active
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPrxProducts(int $page = 1, int $perPage = 50): array
    {
        if (config('prescribe-rx.stub')) {
            return [];
        }

        $body = $this->request()->get('/products', ['page' => $page, 'per_page' => $perPage])->json();

        return $body['data'] ?? [];
    }

    /**
     * `GET /products` — fetch ALL pages and return flat list.
     *
     * Requests `include=productClass,productType` so each item carries its
     * embedded classification objects for the local lookup sync.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllPrxProducts(): array
    {
        if (config('prescribe-rx.stub')) {
            return [];
        }

        $all = [];
        $page = 1;

        do {
            $response = $this->request()->get('/products', [
                'page' => $page,
                'per_page' => 50,
                'include' => 'productClass,productType',
            ]);
            $body = $response->json();
            $items = $body['data'] ?? [];
            $all = array_merge($all, $items);
            $lastPage = $body['meta']['last_page'] ?? 1;
            $page++;
        } while ($page <= $lastPage);

        return $all;
    }

    /**
     * `GET /products/{id}` — single product detail.
     *
     * The detail payload adds fields the list omits, notably
     * ingredients[{id, name, quantity}] used to sync ingredient rows and
     * potency pivots locally.
     *
     * @return array<string, mixed>
     */
    public function getPrxProduct(string $productId): array
    {
        if (config('prescribe-rx.stub')) {
            return [];
        }

        return $this->extractData($this->request()->get("/products/{$productId}"));
    }

    /**
     * `GET /packages`
     *
     * All packages (bundles with embedded plans and product items) for this sales org.
     * Each item: id, package_number, name, slug, description, pricing, product, items[], plans[]
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllPrxPackages(): array
    {
        if (config('prescribe-rx.stub')) {
            return [];
        }

        $all = [];
        $page = 1;

        do {
            $response = $this->request()->get('/packages', ['page' => $page, 'per_page' => 50]);
            $body = $response->json();
            $items = $body['data'] ?? [];
            $all = array_merge($all, $items);
            $lastPage = $body['meta']['last_page'] ?? 1;
            $page++;
        } while ($page <= $lastPage);

        return $all;
    }

    /**
     * `GET /product-types`
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPrxProductTypes(): array
    {
        if (config('prescribe-rx.stub')) {
            return [];
        }

        return $this->extractData($this->request()->get('/product-types'));
    }

    /**
     * `GET /product-classes`
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPrxProductClasses(): array
    {
        if (config('prescribe-rx.stub')) {
            return [];
        }

        return $this->extractData($this->request()->get('/product-classes'));
    }

    // ─── Scheduling (sales-org token) ────────────────────────────────────────

    /**
     * `GET /scheduling/availability/slots`
     *
     * Available appointment slots, filterable by encounter type and date range.
     *
     * @param  array<string, mixed>  $filters  e.g. ['encounter_type_id' => '...', 'date_from' => '...', 'date_to' => '...']
     * @return array<string, mixed>
     */
    public function getAvailabilitySlots(array $filters = []): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => []];
        }

        return $this->extractData($this->request()->get('/scheduling/availability/slots', $filters));
    }

    /**
     * `GET /scheduling/availability/providers`
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getAvailableProviders(array $filters = []): array
    {
        if (config('prescribe-rx.stub')) {
            return ['data' => []];
        }

        return $this->extractData($this->request()->get('/scheduling/availability/providers', $filters));
    }

    /**
     * `POST /scheduling/appointments`
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createAppointment(array $data): array
    {
        if (config('prescribe-rx.stub')) {
            return ['id' => 'stub-appt-id', ...$data];
        }

        return $this->extractData($this->request()->post('/scheduling/appointments', $data));
    }

    // ─── Webhooks (sales-org token) ───────────────────────────────────────────

    /**
     * `POST /webhooks`
     *
     * Register a webhook subscription. The `secret` in the response
     * must be stored immediately — it is not retrievable after this call.
     * Use POST /webhooks/{id}/rotate-secret to issue a replacement.
     *
     * @param  string[]  $events  e.g. ['encounter.*', 'order.*', 'lab.*']
     * @return array<string, mixed>
     */
    public function registerWebhook(string $url, array $events): array
    {
        if (config('prescribe-rx.stub')) {
            return ['id' => 'stub-webhook-id', 'url' => $url, 'events' => $events, 'secret' => 'stub-secret'];
        }

        return $this->extractData($this->request()->post('/webhooks', [
            'url' => $url,
            'events' => $events,
        ]));
    }

    /**
     * `GET /webhooks`
     *
     * List all webhook subscriptions for this sales org.
     *
     * @return array<string, mixed>
     */
    public function listWebhooks(): array
    {
        if (config('prescribe-rx.stub')) {
            return [];
        }

        return $this->extractData($this->request()->get('/webhooks'));
    }

    /**
     * `DELETE /webhooks/{id}`
     */
    public function deleteWebhook(string $subscriptionId): bool
    {
        if (config('prescribe-rx.stub')) {
            return true;
        }

        $response = $this->request()->delete("/webhooks/{$subscriptionId}");

        return $response->successful();
    }

    // ─── Patient Lookup (sales-org token) ────────────────────────────────────

    /**
     * `GET /patients/search` or `GET /patients?filter[email]=X`
     *
     * Searches for a patient chart by email. Returns the first exact match
     * if found, null otherwise.
     *
     * NOTE: `filter[email]` is a fuzzy (LIKE) search on PRX — not exact.
     * A canonical exact-match endpoint (`GET /patients/lookup?email=`) is
     * being added by PRX. Until it ships, verify the returned email against
     * the input email before trusting the match.
     *
     * @return array<string, mixed>|null
     */
    public function findPatientByEmail(string $email): ?array
    {
        if (config('prescribe-rx.stub')) {
            return null;
        }

        $results = $this->extractData(
            $this->request()->get('/patients', ['filter[email]' => $email])
        );

        if (! is_array($results)) {
            return null;
        }

        // Unwrap paginated response if needed
        $rows = isset($results['data']) ? $results['data'] : $results;

        foreach ($rows as $row) {
            if (isset($row['email']) && strtolower($row['email']) === strtolower($email)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * `POST /telehealth/intake/unified`
     *
     * Single call that creates patient + encounter + intake answers. The
     * input DTO carries everything; this method merges in the configured
     * `client_id` / `sales_org_id` from IntegrationSettings if the request
     * doesn't already specify them.
     */
    public function submitUnifiedIntake(UnifiedIntakeRequestData $data, ?string $idempotencyKey = null): UnifiedIntakeResponseData
    {
        $raw = $this->withConfiguredOrg($data->toArray());
        $payload = self::stripNulls($raw);

        // `answers` was always present on the wire before the null-strip, and
        // an encounter with no answers is a legitimate submission (the embed
        // collects them instead). Restore the key rather than let an empty
        // answer set change the payload's shape — dropping it is a question
        // for their validator that we cannot answer from here.
        //
        // The empty encoding differs deliberately: this sends `{}` where the
        // pre-strip payload sent `[]`. Both decode identically in PHP, and
        // `{}` is the truer encoding of their `array<string, mixed>` contract
        // — but a strict non-PHP validator could tell them apart, so this is
        // worth confirming on the first real sandbox submission.
        if (array_key_exists('answers', $raw) && ! array_key_exists('answers', $payload)) {
            $payload['answers'] = (object) [];
        }

        if (config('prescribe-rx.stub')) {
            return UnifiedIntakeResponseData::from([
                'encounter_id' => '019d5561-7b2b-7096-8d90-4ee49ef2ede8',
                'encounter_number' => 'ENC-STUB-1',
                'patient_chart_id' => '019d5561-7b24-7096-baa7-85485643b0a7',
                'patient_number' => 'PAT-STUB-1',
                'status' => 'pending_intake',
                'status_label' => 'Pending Patient Intake',
                'encounter_type' => $data->encounter_type_name ?? 'Stub Encounter',
                'completeness_score' => 80,
                'workflow' => [
                    'user_created' => true,
                    'patient_chart_created' => true,
                    'encounter_created' => true,
                    'intake_stored' => true,
                ],
            ]);
        }

        $request = $this->request();

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $response = $request->post('/telehealth/intake/unified', $payload);
        $body = $this->extractData($response);

        return UnifiedIntakeResponseData::from($body);
    }

    /**
     * Recursively drop null values from a payload.
     *
     * Required by the selection arrays: `products[]` / `packages[]` accept
     * EXACTLY ONE identifier per line, and a spatie DTO serialises its unset
     * identifiers as explicit nulls. Empty arrays are dropped for the same
     * reason — an empty `packages: []` alongside a populated `products` reads
     * as a deliberate selection of nothing.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function stripNulls(array $payload): array
    {
        $isList = array_is_list($payload);
        $clean = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $value = self::stripNulls($value);

                if ($value === []) {
                    continue;
                }
            }

            $clean[$key] = $value;
        }

        // Dropping an element from a list leaves an index gap, and PHP encodes
        // a gapped array as a JSON object rather than an array — which would
        // turn `products: [...]` into `products: {"1": ...}` on the wire.
        return $isList ? array_values($clean) : $clean;
    }

    // ─── Internals ────────────────────────────────────────────────────────

    /**
     * Build a PendingRequest authenticated with a patient-scoped token
     * (from `issuePatientToken()`). Used for all `/me/patient/*` calls
     * and for encounter video-token (PHI audit requires patient identity).
     */
    protected function patientRequest(string $patientToken): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($patientToken)
            ->withHeaders(config('prescribe-rx.default_headers'))
            ->connectTimeout((int) config('prescribe-rx.http.connect_timeout', 5))
            ->timeout((int) config('prescribe-rx.http.request_timeout', 30))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Build a configured PendingRequest. Throws PrescribeRxException if the
     * integration isn't enabled or no token is configured.
     */
    protected function request(): PendingRequest
    {
        if (! $this->settings->prescribe_rx_enabled || empty($this->settings->prescribe_rx_api_token)) {
            throw PrescribeRxException::notConfigured();
        }

        return Http::baseUrl($this->baseUrl())
            ->withToken($this->settings->prescribe_rx_api_token)
            ->withHeaders(config('prescribe-rx.default_headers'))
            ->connectTimeout((int) config('prescribe-rx.http.connect_timeout', 5))
            ->timeout((int) config('prescribe-rx.http.request_timeout', 30))
            ->retry(
                (int) config('prescribe-rx.http.retry_times', 2),
                (int) config('prescribe-rx.http.retry_sleep_ms', 200),
                throw: false,
            )
            ->acceptJson()
            ->asJson();
    }

    protected function baseUrl(): string
    {
        $env = $this->settings->prescribe_rx_environment === 'production' ? 'production' : 'sandbox';

        return rtrim((string) config("prescribe-rx.urls.{$env}"), '/');
    }

    /**
     * Merge the configured org IDs into a unified-intake payload if the
     * request didn't specify them. Drops nulls so the API uses its own
     * fallback (the token's authenticated org).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withConfiguredOrg(array $payload): array
    {
        if (empty($payload['client_id']) && filled($this->settings->prescribe_rx_client_id)) {
            $payload['client_id'] = $this->settings->prescribe_rx_client_id;
        }
        if (empty($payload['sales_org_id']) && filled($this->settings->prescribe_rx_sales_org_id)) {
            $payload['sales_org_id'] = $this->settings->prescribe_rx_sales_org_id;
        }

        return array_filter($payload, fn ($v) => $v !== null);
    }

    /**
     * Validate a successful prescribe-rx response and return its `data`
     * envelope. Throws on any non-2xx with the API's error message.
     *
     * @return array<string, mixed>
     */
    protected function extractData(Response $response): array
    {
        if (! $response->successful()) {
            try {
                Log::warning('PrescribeRx non-2xx', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            } catch (Throwable) {
                // ignore log failures
            }
            throw PrescribeRxException::fromResponse($response);
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new PrescribeRxException('PrescribeRx returned a non-JSON body.', $response->status());
        }

        // Both list and detail endpoints wrap payload in `{ success, data, ... }`.
        // Some endpoints return `data` as object, some as array — return as-is.
        return $body['data'] ?? [];
    }
}
