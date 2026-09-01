<?php

namespace App\Services\Telehealth;

use App\Contracts\Telehealth\TelehealthProviderInterface;
use App\Data\PrescribeRx\PatientData;
use App\Data\PrescribeRx\UnifiedIntakeRequestData;
use App\Models\Lead;
use App\Services\PrescribeRx\Client;
use App\Services\PrescribeRx\Embed\PrxEmbedPayloadBuilder;
use App\Settings\IntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Telehealth provider implementation backed by the prescribe-rx API.
 *
 * This class wraps the existing `App\Services\PrescribeRx\Client` — it does
 * NOT duplicate HTTP logic. Methods already implemented on Client delegate
 * directly; methods not yet on Client issue HTTP calls via the same
 * `$this->http()` helper pattern so they are ready to be extracted into
 * Client methods later with no callers needing to change.
 *
 * Known gaps (tracked):
 *   - `supportsPatientPortalAuth()` / `issuePatientToken()` — PRX endpoint
 *     not yet available; returns false / null until provisioned.
 */
class PrescribeRxProvider implements TelehealthProviderInterface
{
    public function __construct(
        protected readonly Client $client,
        protected readonly PrxEmbedPayloadBuilder $embedBuilder,
        protected readonly IntegrationSettings $settings,
    ) {}

    // ─── Provider metadata ────────────────────────────────────────────────

    public function getName(): string
    {
        return 'PrescribeRx';
    }

    public function getSlug(): string
    {
        return 'prescribe-rx';
    }

    // ─── Capability flags ─────────────────────────────────────────────────

    public function supportsEmbed(): bool
    {
        return true;
    }

    public function supportsPatientPortalAuth(): bool
    {
        // Not yet available in the PRX API — tracked for future provisioning.
        return false;
    }

    // ─── Catalog ──────────────────────────────────────────────────────────

    public function getCatalog(): array
    {
        return $this->http()->get('/catalog')->json('data', []);
    }

    public function listEncounterTypes(): array
    {
        $types = $this->client->listEncounterTypes();

        // Client returns typed DTOs; convert to plain arrays to honour the interface contract.
        return array_map(fn ($dto) => $dto->toArray(), $types);
    }

    public function getEncounterTypeSchema(string $encounterTypeId): array
    {
        return $this->client->getEncounterTypeSchema($encounterTypeId)->toArray();
    }

    // ─── Patient lifecycle ────────────────────────────────────────────────

    public function createPatient(PatientData $data): array
    {
        return $this->http()->post('/patients', $data->toArray())->json('data', []);
    }

    public function getPatient(string $providerPatientId): array
    {
        return $this->http()->get("/patients/{$providerPatientId}")->json('data', []);
    }

    public function updatePatient(string $providerPatientId, PatientData $data): array
    {
        return $this->http()->patch("/patients/{$providerPatientId}", $data->toArray())->json('data', []);
    }

    public function findPatientByEmail(string $email): ?array
    {
        $response = $this->http()->get('/patients', ['email' => $email]);
        $items = $response->json('data', []);

        return ! empty($items) ? $items[0] : null;
    }

    // ─── Patient chart ────────────────────────────────────────────────────

    public function getPatientChart(string $providerPatientId, array $include = []): array
    {
        $query = $include ? ['include' => implode(',', $include)] : [];

        return $this->http()->get("/patients/{$providerPatientId}/chart", $query)->json('data', []);
    }

    public function addPatientVital(string $providerPatientId, array $vital): array
    {
        return $this->http()->post("/patients/{$providerPatientId}/chart/vitals", $vital)->json('data', []);
    }

    public function addPatientAllergy(string $providerPatientId, array $allergy): array
    {
        return $this->http()->post("/patients/{$providerPatientId}/chart/allergies", $allergy)->json('data', []);
    }

    public function addPatientMedication(string $providerPatientId, array $medication): array
    {
        return $this->http()->post("/patients/{$providerPatientId}/chart/medications", $medication)->json('data', []);
    }

    public function addPatientCondition(string $providerPatientId, array $condition): array
    {
        return $this->http()->post("/patients/{$providerPatientId}/chart/conditions", $condition)->json('data', []);
    }

    // ─── Intake / Encounters ──────────────────────────────────────────────

    public function submitIntake(array $payload, ?string $idempotencyKey = null): array
    {
        $request = UnifiedIntakeRequestData::from($payload);

        $response = $this->client->submitUnifiedIntake($request, $idempotencyKey);

        return $response->toArray();
    }

    public function evaluatePreclusions(string $encounterTypeId, array $answers, ?string $patientChartId = null): array
    {
        $payload = array_filter([
            'encounter_type_id' => $encounterTypeId,
            'answers' => $answers,
            'patient_chart_id' => $patientChartId,
        ], fn ($v) => $v !== null);

        return $this->http()->post('/telehealth/preclusions/evaluate', $payload)->json('data', []);
    }

    public function getEncounter(string $encounterId, array $include = []): array
    {
        $query = $include ? ['include' => implode(',', $include)] : [];

        return $this->http()->get("/telehealth/encounters/{$encounterId}", $query)->json('data', []);
    }

    public function getEncounterStatus(string $encounterId): array
    {
        return $this->http()->get("/telehealth/encounters/{$encounterId}/status")->json('data', []);
    }

    public function amendEncounterAnswers(string $encounterId, array $answers): array
    {
        return $this->http()->patch(
            "/telehealth/encounters/{$encounterId}/answers",
            ['answers' => $answers]
        )->json('data', []);
    }

    public function listPatientEncounters(string $providerPatientId, array $filters = []): array
    {
        return $this->http()
            ->get("/patients/{$providerPatientId}/encounters", $filters)
            ->json('data', []);
    }

    // ─── Orders ───────────────────────────────────────────────────────────

    public function getOrder(string $orderId, array $include = []): array
    {
        $query = $include ? ['include' => implode(',', $include)] : [];

        return $this->http()->get("/orders/{$orderId}", $query)->json('data', []);
    }

    public function listPatientOrders(string $providerPatientId, array $filters = []): array
    {
        return $this->http()
            ->get("/patients/{$providerPatientId}/orders", $filters)
            ->json('data', []);
    }

    public function previewCart(array $lines, ?string $couponCode = null, ?array $shippingAddress = null): array
    {
        $payload = array_filter([
            'lines' => $lines,
            'coupon_code' => $couponCode,
            'shipping_address' => $shippingAddress,
        ], fn ($v) => $v !== null);

        return $this->http()->post('/cart/preview', $payload)->json('data', []);
    }

    public function validateCoupon(string $code, array $lines = []): array
    {
        return $this->http()->post('/coupons/validate', [
            'code' => $code,
            'lines' => $lines,
        ])->json('data', []);
    }

    // ─── Prescriptions ────────────────────────────────────────────────────

    public function listPatientPrescriptions(string $providerPatientId, array $filters = []): array
    {
        return $this->http()
            ->get("/patients/{$providerPatientId}/prescriptions", $filters)
            ->json('data', []);
    }

    // ─── Subscriptions ────────────────────────────────────────────────────

    public function listPatientSubscriptions(string $providerPatientId): array
    {
        return $this->http()->get("/patients/{$providerPatientId}/subscriptions")->json('data', []);
    }

    public function createSubscription(array $data): array
    {
        return $this->http()->post('/subscriptions', $data)->json('data', []);
    }

    public function updateSubscription(string $subscriptionId, array $data): array
    {
        return $this->http()->patch("/subscriptions/{$subscriptionId}", $data)->json('data', []);
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->http()->delete("/subscriptions/{$subscriptionId}");
    }

    // ─── Lab orders ───────────────────────────────────────────────────────

    public function createLabOrder(array $data): array
    {
        return $this->http()->post('/lab-orders', $data)->json('data', []);
    }

    public function listPatientLabOrders(string $providerPatientId): array
    {
        return $this->http()->get("/patients/{$providerPatientId}/lab-orders")->json('data', []);
    }

    public function getLabCatalog(): array
    {
        return $this->http()->get('/lab-orders/catalog')->json('data', []);
    }

    // ─── Scheduling ───────────────────────────────────────────────────────

    public function getProviderAvailability(string $providerId, string $startDate, string $endDate): array
    {
        return $this->http()->get("/providers/{$providerId}/availability", [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ])->json('data', []);
    }

    public function bookAppointment(array $data): array
    {
        return $this->http()->post('/appointments', $data)->json('data', []);
    }

    public function listPatientAppointments(string $providerPatientId): array
    {
        return $this->http()->get("/patients/{$providerPatientId}/appointments")->json('data', []);
    }

    // ─── Webhooks ─────────────────────────────────────────────────────────

    public function registerWebhook(string $eventType, string $endpointUrl): array
    {
        return $this->http()->post('/webhooks', [
            'event_type' => $eventType,
            'endpoint_url' => $endpointUrl,
        ])->json('data', []);
    }

    public function listWebhooks(): array
    {
        return $this->http()->get('/webhooks')->json('data', []);
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->http()->delete("/webhooks/{$webhookId}");
    }

    // ─── Embed / patient portal ───────────────────────────────────────────

    public function buildEmbedPayload(Lead $lead): ?array
    {
        return $this->embedBuilder->forLead($lead);
    }

    public function issuePatientToken(string $providerPatientId): ?string
    {
        // supportsPatientPortalAuth() is false; endpoint not yet available in PRX.
        return null;
    }

    // ─── Internals ────────────────────────────────────────────────────────

    /**
     * Build a configured PendingRequest using the same base URL and auth
     * token as the injected Client. Used for endpoints not yet implemented
     * on the Client class.
     *
     * Note: error handling here returns raw responses. Callers that need
     * strict failure handling should use Client methods (which call
     * `extractData()` and throw `PrescribeRxException` on non-2xx).
     */
    protected function http(): PendingRequest
    {
        $env = $this->settings->prescribe_rx_environment === 'production' ? 'production' : 'sandbox';
        $baseUrl = rtrim((string) config("prescribe-rx.urls.{$env}"), '/');

        return Http::baseUrl($baseUrl)
            ->withToken((string) $this->settings->prescribe_rx_api_token)
            ->withHeaders((array) config('prescribe-rx.default_headers', []))
            ->connectTimeout((int) config('prescribe-rx.http.connect_timeout', 5))
            ->timeout((int) config('prescribe-rx.http.request_timeout', 30))
            ->acceptJson()
            ->asJson();
    }
}
