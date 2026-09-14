<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\IssuePortalTokenAction;
use App\Models\Patient;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LabHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('prescribe-rx.stub', false);
        Http::preventStrayRequests();
        $this->patient = Patient::factory()->withPrxChart()->create();
        Sanctum::actingAs($this->patient, ['patient:*']);
        $this->mock(IssuePortalTokenAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->andReturn('synthetic-patient-token');
            $mock->shouldReceive('renew')->andReturn('renewed-patient-token');
        });
    }

    private function envelope(): array
    {
        return ['data' => [[
            'id' => 'synthetic-lab-id', 'lab_order_number' => 'LAB-100',
            'patient_chart_id' => $this->patient->prx_patient_chart_id,
            'status' => 'results_received', 'collection_method' => 'walk_in_draw',
            'ordered_at' => '2026-09-14T12:00:00Z', 'results_received_at' => null,
            'lab_cost' => 120, 'billing_type' => 'client_bill', 'encounter_id' => 'private-encounter',
            'results' => [['value' => 'private-result']], 'checkout_url' => 'https://private.example.test',
        ]], 'meta' => ['pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1], 'private' => 'omit']];
    }

    public function test_only_session_chart_patient_token_and_bounded_pagination_reach_provider(): void
    {
        Http::fake(['*/patients/*/labs*' => function (HttpRequest $request, array $options) {
            $this->assertSame('php://memory', stream_get_meta_data($options['sink'])['uri']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['decode_content']);
            $this->assertTrue($request->hasHeader('Accept-Encoding', 'identity'));

            return Http::response($this->envelope());
        }]);
        $response = $this->getJson('/api/v1/patient/labs?patient_chart_id=another&filter[patient_chart_id]=another&include=orderTests.results')
            ->assertOk()->assertJsonPath('data.items.0.lab_order_number', 'LAB-100')
            ->assertJsonPath('data.pagination.total', 1)->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['id', 'lab_order_number', 'status', 'collection_method', 'ordered_at', 'results_received_at'], array_keys($response->json('data.items.0')));
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($response->json('data.pagination')));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/patients/'.$this->patient->prx_patient_chart_id.'/labs')
            && $r->hasHeader('Authorization', 'Bearer synthetic-patient-token')
            && $r->data() === ['page' => 1, 'per_page' => 20]);
        Http::assertSentCount(1);
    }

    public function test_empty_history_is_distinct_from_malformed_or_wrong_chart_data(): void
    {
        $empty = ['data' => [], 'meta' => ['pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]]];
        $wrong = $this->envelope();
        $wrong['data'][0]['patient_chart_id'] = 'another-chart';
        $nested = $this->envelope();
        $nested['data'][0]['status'] = ['secret' => 'private'];
        $pagination = $this->envelope();
        $pagination['meta']['pagination']['current_page'] = 2;
        Http::fake(['*/patients/*/labs*' => Http::sequence()->push($empty)->push($wrong)->push($nested)->push($pagination)->push(['data' => []])]);
        $this->getJson('/api/v1/patient/labs')->assertOk()->assertJsonPath('data.items', []);
        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/api/v1/patient/labs')->assertStatus(502)->assertDontSee('private');
        }
    }

    public function test_invalid_pagination_and_unlinked_accounts_make_no_provider_request(): void
    {
        foreach (['page=0', 'page=10001', 'page[]=1', 'per_page=101', 'per_page=0'] as $query) {
            $this->getJson('/api/v1/patient/labs?'.$query)->assertUnprocessable();
        }
        Sanctum::actingAs(Patient::factory()->create(['prx_patient_chart_id' => null]), ['patient:*']);
        $this->getJson('/api/v1/patient/labs')->assertStatus(409)->assertJsonPath('code', 'no_linked_chart');
        Http::assertNothingSent();
    }

    public function test_wrong_identity_abilities_and_required_two_factor_are_preserved(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson('/api/v1/patient/labs')->assertUnauthorized();
        Sanctum::actingAs($this->patient, ['order:read']);
        $this->getJson('/api/v1/patient/labs')->assertForbidden();
        $settings = app(PortalSettings::class);
        $settings->two_factor_policy = 'required';
        $settings->save();
        Sanctum::actingAs($this->patient, ['patient:*']);
        $this->getJson('/api/v1/patient/labs')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_oversized_or_compressed_provider_bodies_are_rejected(): void
    {
        Http::fake(['*/patients/*/labs*' => Http::sequence()
            ->push(str_repeat('x', 524289), 200, ['Content-Type' => 'application/json'])
            ->push(gzencode(json_encode($this->envelope())), 200, ['Content-Encoding' => 'gzip'])]);
        $this->getJson('/api/v1/patient/labs')->assertStatus(502);
        $this->getJson('/api/v1/patient/labs')->assertStatus(502);
    }

    public function test_connection_failure_is_sanitized_and_not_misrepresented_as_empty_history(): void
    {
        Log::spy();
        Http::fake(['*/patients/*/labs*' => Http::failedConnection('private-chart-and-provider-url')]);
        $this->getJson('/api/v1/patient/labs')->assertStatus(503)->assertDontSee('private-chart-and-provider-url');
        Log::shouldNotHaveReceived('error', fn ($message, $context = []) => str_contains(json_encode([$message, $context]), 'private-chart-and-provider-url'));
        Http::assertSentCount(1);
    }

    public function test_provider_failures_never_log_or_relay_clinical_bodies_and_401_renews_once(): void
    {
        Log::spy();
        Http::fake(['*/patients/*/labs*' => Http::sequence()
            ->push(['message' => 'private-clinical-response'], 503)
            ->push(['message' => 'expired-private-token'], 401)
            ->push($this->envelope())]);
        $this->getJson('/api/v1/patient/labs')->assertStatus(502)->assertDontSee('private-clinical-response');
        $this->getJson('/api/v1/patient/labs')->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer renewed-patient-token'));
        Log::shouldNotHaveReceived('warning', fn ($message, $context = []) => str_contains(json_encode([$message, $context]), 'private-clinical-response'));
        Http::assertSentCount(3);
    }
}
