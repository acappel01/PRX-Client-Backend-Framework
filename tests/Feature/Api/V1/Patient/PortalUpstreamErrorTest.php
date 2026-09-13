<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Models\Patient;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a portal client learns when the clinical provider fails.
 *
 * Every upstream failure reaches us as one exception type, and before this it
 * rendered as a bare `500 {"message":"Server Error"}` — measured on the live
 * sandbox, for BOTH a rejected weight and a provider crash. That single body
 * is unusable by the one screen that needs it most:
 *
 *   * `POST /me/patient/vitals` 422s when the patient mistypes a weight.
 *     Nothing was written; correcting the value and resubmitting is right.
 *   * The same endpoint 500s on EVERY successful call (P0-7 — it orders by a
 *     column that does not exist, after the insert has committed). The row IS
 *     there; resubmitting duplicates a clinical reading into the trend the
 *     Health screen is built around.
 *
 * A form cannot tell those apart from one status, so it must either invite the
 * duplicate or dead-end the typo. These tests pin the distinction, and pin that
 * restoring it did not also start forwarding the provider's stack traces.
 */
class PortalUpstreamErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('prescribe-rx.stub', false);

        $settings = app(IntegrationSettings::class);
        $settings->prescribe_rx_enabled = true;
        $settings->prescribe_rx_api_token = 'test-org-token';

        Sanctum::actingAs(Patient::factory()->withPrxChart()->create(), ['*']);
    }

    /**
     * @param  array<string, mixed>  $vitalsResponse
     */
    private function fakePrx(array $vitalsResponse, int $status): void
    {
        Http::fake([
            '*/patients/*/issue-token' => Http::response([
                'data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()],
            ], 201),
            '*/me/patient/vitals' => Http::response($vitalsResponse, $status),
        ]);
    }

    public function test_a_rejected_reading_comes_back_as_422_with_the_field_named(): void
    {
        // The provider's own validation body, keyed by its REQUEST field names
        // — which is what the form sent, so a form can point at the input.
        $this->fakePrx([
            'message' => 'The weight lbs field must not be greater than 1000.',
            'errors' => ['weight_lbs' => ['The weight lbs field must not be greater than 1000.']],
        ], 422);

        $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 99999])
            ->assertStatus(422)
            ->assertJsonPath('errors.weight_lbs.0', 'The weight lbs field must not be greater than 1000.');
    }

    public function test_a_provider_crash_is_a_502_and_carries_none_of_its_stack(): void
    {
        // Verbatim shape of the P0-7 body observed on the sandbox: the SQL
        // statement, with a patient_chart_id inside it.
        $this->fakePrx([
            'message' => "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'recorded_at' in 'order clause' "
                ."(select * from `patient_vitals` where `patient_chart_id` = '01a07ea4-282e-70d1-b350-6fc58731c738' "
                .'order by `recorded_at` desc) at /var/www/html/prx-demo/app/Http/Controllers/Api/V1/Me/PatientSelfServiceController.php:155',
        ], 500);

        $response = $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4]);

        // 502, not 500: the fault is upstream and the caller did nothing wrong.
        // The status is the whole signal a client has for "do not resubmit".
        $response->assertStatus(502);

        $body = $response->getContent();

        foreach (['SQLSTATE', 'recorded_at', 'patient_vitals', 'prx-demo', 'PatientSelfServiceController', '01a07ea4'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "The provider's error body leaked '{$leak}' to the patient.");
        }
    }

    public function test_a_502_and_a_422_are_not_the_same_response(): void
    {
        // The regression this whole handler exists to prevent. Before it, both
        // of the above were `500 {"message":"Server Error"}` — identical bytes.
        //
        // One fake answering both, because a second Http::fake() call does not
        // replace the first set of stubs — the request decides which it gets.
        Http::fake([
            '*/patients/*/issue-token' => Http::response([
                'data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()],
            ], 201),
            '*/me/patient/vitals' => function ($request) {
                return ((float) ($request->data()['weight_lbs'] ?? 0)) > 1000
                    ? Http::response(['message' => 'nope', 'errors' => ['weight_lbs' => ['too big']]], 422)
                    : Http::response(['message' => 'boom'], 500);
            },
        ]);

        $rejected = $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 99999]);
        $crashed = $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4]);

        $this->assertNotSame($rejected->getStatusCode(), $crashed->getStatusCode());
    }

    public function test_an_upstream_401_never_tells_the_patient_to_sign_in_again(): void
    {
        // By the time this reaches the handler, PortalController has already
        // evicted the cached patient token and re-minted one with the ORG
        // credential. A 401 on the second attempt means the provider rejected
        // OUR token — there is no patient credential in that call chain at all.
        //
        // Answering 401 puts the portal in a loop: every screen renders "your
        // session expired", the patient signs in (which succeeds — it is our
        // own Sanctum), and lands back on the same message. Rotating the
        // provider token without updating IntegrationSettings does exactly
        // this, and that rotation is currently outstanding.
        Http::fake([
            '*/patients/*/issue-token' => Http::response([
                'data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()],
            ], 201),
            '*/me/patient/vitals' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4])
            ->assertStatus(502);
    }

    public function test_an_upstream_404_stays_a_404_rather_than_becoming_a_fault_of_ours(): void
    {
        $this->fakePrx(['message' => 'Patient chart not found.'], 404);

        $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4])
            ->assertStatus(404);
    }

    // ─── Correlation id ──────────────────────────────────────────────

    /** Equality, not "looks like a uuid": the id the patient reads must be the one the provider saw. */
    public function test_a_provider_failure_carries_the_request_id_the_provider_was_sent(): void
    {
        $this->fakePrx(['message' => 'boom'], 500);

        $response = $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4], ['X-Request-ID' => 'evil<script>'])
            ->assertStatus(502);

        $sent = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), '/me/patient/vitals'))
            ->sole()
            ->header('X-Request-ID')[0] ?? null;

        $this->assertNotNull($sent);
        $this->assertSame($sent, $response->json('request_id'));
        $this->assertSame($sent, $response->headers->get('X-Request-ID'));
        // A caller-supplied id is never used — it would write their bytes into two systems' logs.
        $this->assertNotSame('evil<script>', $sent);
        $this->assertStringNotContainsString('evil', $response->getContent());
        $this->assertNull($response->json('upstream_request_id'), 'Same id upstream: no second reference.');
    }

    public function test_an_echoed_upstream_id_is_not_repeated(): void
    {
        Http::fake([
            '*/patients/*/issue-token' => Http::response([
                'data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()],
            ], 201),
            // What the provider actually does: echo the id it was sent.
            '*/me/patient/vitals' => fn ($request) => Http::response(['message' => 'boom', 'meta' => ['request_id' => $request->header('X-Request-ID')[0]]], 500),
        ]);

        $response = $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4])->assertStatus(502);

        $this->assertNotNull($response->json('request_id'));
        $this->assertArrayNotHasKey('upstream_request_id', $response->json());
    }

    public function test_an_upstream_id_that_is_not_an_id_is_dropped(): void
    {
        $this->fakePrx(['message' => 'boom', 'meta' => ['request_id' => '<script>alert(1)</script>']], 500);

        $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4])
            ->assertStatus(502)
            ->assertJsonMissingPath('upstream_request_id');
    }

    public function test_the_request_id_header_is_on_an_unauthenticated_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/patient/home', ['Authorization' => 'Bearer nope'])
            ->assertUnauthorized()
            ->assertHeader('X-Request-ID');
    }

    public function test_a_different_upstream_id_is_passed_along(): void
    {
        $this->fakePrx(['message' => 'boom', 'meta' => ['request_id' => 'prx-minted-id-123']], 500);

        $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 178.4])
            ->assertStatus(502)
            ->assertJsonPath('upstream_request_id', 'prx-minted-id-123');
    }

    public function test_a_rejected_value_also_carries_a_reference(): void
    {
        $this->fakePrx(['message' => 'nope', 'errors' => ['weight_lbs' => ['too big']]], 422);

        $response = $this->postJson('/api/v1/patient/vitals', ['weight_lbs' => 99999])->assertStatus(422);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response->json('request_id'));
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('request_id'));
    }
}
