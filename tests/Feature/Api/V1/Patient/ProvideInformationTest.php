<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Settings\UpdatePortalSettingsAction;
use App\Data\Settings\PortalSettingsData;
use App\Models\Patient;
use App\Settings\IntegrationSettings;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0-4: what a held visit needs, and sending it. Shapes copied from the
 * provider's handler (PatientSelfServiceController::encounterRequirements /
 * provideInformation and CompleteEncounterIntakeAction at prx-demo develop).
 */
class ProvideInformationTest extends TestCase
{
    use RefreshDatabase;

    private const ENCOUNTER = '01a07dfe-6307-727b-8ef4-acddfeebcafa';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('prescribe-rx.stub', false);
        $settings = app(IntegrationSettings::class);
        $settings->prescribe_rx_enabled = true;
        $settings->prescribe_rx_api_token = 'test-org-token';
        $settings->save();

        Sanctum::actingAs(Patient::factory()->withPrxChart()->create(), ['*']);
    }

    private function fake(array $body, int $status = 200): void
    {
        Http::fake([
            '*/patients/*/issue-token' => Http::response(['data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()]], 201),
            '*/me/patient/encounters/*' => Http::response($body, $status),
        ]);
    }

    private function requirements(): array
    {
        return ['success' => true, 'data' => [
            'encounter_id' => self::ENCOUNTER,
            'status' => 'requires_information',
            'resolvable_via_api' => true,
            'info_request_message' => 'Please send a photo of your ID.',
            'completeness_pct' => 60,
            'items' => [
                ['slug' => 'id_front', 'label' => 'Government ID (front)', 'type' => 'file', 'satisfied' => false, 'internal' => 'x'],
                ['slug' => 'drivers_license_number', 'label' => 'Driver license number', 'type' => 'text', 'satisfied' => true],
            ],
            'missing' => ['id_front'],
            'intake_id' => 'secret-intake',
        ]];
    }

    private function upstream(): HttpRequest
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), '/me/patient/encounters/'))
            ->sole();
    }

    public function test_requirements_keep_what_the_screen_needs_and_use_the_operators_wording(): void
    {
        $settings = app(PortalSettings::class);
        $settings->requirement_labels = ['id_front' => 'A photo of the front of your ID'];
        $settings->save();
        $this->fake($this->requirements());

        $response = $this->getJson('/api/v1/patient/encounters/'.self::ENCOUNTER.'/requirements')
            ->assertOk()
            ->assertJsonPath('data.resolvable_via_api', true)
            ->assertJsonPath('data.completeness_pct', 60)
            ->assertJsonPath('data.missing.0', 'id_front')
            ->assertJsonPath('data.items.0.label', 'A photo of the front of your ID')
            ->assertJsonPath('data.items.0.type', 'file')
            ->assertJsonPath('data.items.0.satisfied', false)
            ->assertJsonPath('data.items.1.label', 'Driver license number');

        $this->assertArrayNotHasKey('internal', $response->json('data.items.0'));
        $this->assertStringNotContainsString('secret-intake', $response->getContent());
    }

    /** Http::fake matches any body — so assert the recorded request really was multipart with the file. */
    public function test_photos_and_fields_go_upstream_as_multipart_and_nothing_else_does(): void
    {
        $this->fake(['success' => true, 'data' => ['released' => true, 'missing' => [], 'missing_fields' => [], 'missing_docs' => []]]);

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', [
            'id_upload' => UploadedFile::fake()->image('my-licence.jpg', 800, 500),
            'drivers_license_number' => 'D1234567',
            'patient_chart_id' => 'someone-else',
            'sales_organization_id' => '99',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.released', true);

        $sent = $this->upstream();
        $this->assertSame('POST', $sent->method());
        $this->assertStringStartsWith('multipart/form-data', $sent->header('Content-Type')[0]);
        $this->assertTrue($sent->hasFile('id_front'), 'id_upload is sent as id_front.');
        $this->assertFalse($sent->hasFile('id_upload'));
        $parts = collect($sent->data())->pluck('contents', 'name');
        $this->assertSame('D1234567', $parts['drivers_license_number']);
        $this->assertFalse($parts->has('patient_chart_id'));
        $this->assertFalse($parts->has('sales_organization_id'));
        $this->assertSame(['Bearer patient-token'], $sent->header('Authorization'));
        // The patient's own filename does not travel.
        $this->assertStringNotContainsString('my-licence', json_encode($sent->data()));
    }

    /** The provider COMMITS what it received and then answers 422 — that must not read as "nothing saved". */
    public function test_saved_but_still_incomplete_is_a_200_that_says_so(): void
    {
        $this->fake(['success' => false, 'message' => 'Some required information is still missing.', 'errors' => ['missing' => ['id_back'], 'missing_fields' => [], 'missing_docs' => ['id_back']]], 422);

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', [
            'id_front' => UploadedFile::fake()->image('a.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.released', false)
            ->assertJsonPath('data.missing_docs.0', 'id_back');
    }

    /** A field error that merely has a key called `missing` is still a field error, not a partial save. */
    public function test_a_field_error_is_not_mistaken_for_a_partial_save(): void
    {
        $this->fake(['message' => 'The missing field is invalid.', 'errors' => ['missing' => ['The missing field is invalid.']]], 422);

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', ['drivers_license_state' => 'TX'], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonMissingPath('data.released');
    }

    public function test_a_visit_no_longer_waiting_is_a_409_with_a_code(): void
    {
        $this->fake(['success' => false, 'message' => 'This intake is not awaiting completion.'], 409);

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', ['drivers_license_state' => 'TX'], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_awaiting_completion');
    }

    public function test_a_provider_field_error_keeps_its_field(): void
    {
        $this->fake(['message' => 'The id front field must be an image.', 'errors' => ['id_front' => ['The id front field must be an image.']]], 422);

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', [
            'id_front' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.id_front.0', 'The id front field must be an image.');
    }

    /** assertNothingSent: a faked upstream 422 would otherwise pass this on its own. */
    public function test_svg_and_non_images_are_refused_before_the_provider(): void
    {
        $this->fake(['message' => 'nope', 'errors' => ['id_front' => ['nope']]], 422);

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', [
            'id_front' => UploadedFile::fake()->createWithContent('id.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('id_front');

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', [
            'id_front' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('id_front');

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', [
            'id_front' => UploadedFile::fake()->image('big.jpg')->size(10241),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('id_front');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/provide-information'));
    }

    public function test_an_empty_submission_sends_nothing(): void
    {
        $this->fake([]);

        $this->postJson('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', [])
            ->assertUnprocessable();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/provide-information'));
    }

    public function test_an_account_without_a_record_sends_nothing(): void
    {
        Sanctum::actingAs(Patient::factory()->create(['prx_patient_chart_id' => null]), ['*']);
        $this->fake($this->requirements());

        // The code is what lets the portal say "connect your record" without
        // reading every 409 that way — the provider's own conflicts are 409s too.
        $this->getJson('/api/v1/patient/encounters/'.self::ENCOUNTER.'/requirements')
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_linked_chart');

        Http::assertNothingSent();
    }

    /**
     * Each request may carry 40 MB this server buffers and relays, so the
     * general 120/min limit bounds nothing. The eleventh inside ten minutes is
     * refused before it is relayed anywhere.
     */
    public function test_sending_is_limited_per_account(): void
    {
        $this->fake(['success' => true, 'data' => ['released' => true, 'missing' => []]]);

        for ($i = 0; $i < 10; $i++) {
            $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', ['drivers_license_state' => 'TX'], ['Accept' => 'application/json'])
                ->assertOk();
        }

        $sent = count(Http::recorded());

        $this->post('/api/v1/patient/encounters/'.self::ENCOUNTER.'/provide-information', ['drivers_license_state' => 'TX'], ['Accept' => 'application/json'])
            ->assertTooManyRequests();

        $this->assertCount($sent, Http::recorded());

        // Reading what is needed is not an upload and stays unaffected.
        $this->fake($this->requirements());
        $this->getJson('/api/v1/patient/encounters/'.self::ENCOUNTER.'/requirements')->assertOk();
    }

    public function test_the_label_setting_saves_only_real_slugs_and_plain_text(): void
    {
        $settings = app(PortalSettings::class);
        $data = PortalSettingsData::validateAndCreate([
            'security_events_retention_days' => 730, 'session_idle_minutes' => 30, 'session_max_hours' => 12,
            'two_factor_policy' => 'off', 'trusted_device_days' => 30,
            'requirement_labels' => ['ID_Front ' => '<b>Front</b> of your ID', 'bad slug!' => 'x', 'id_back' => '  '],
        ]);

        app(UpdatePortalSettingsAction::class)->execute($data);

        $this->assertSame(['id_front' => 'Front of your ID'], $settings->refresh()->requirement_labels);
    }
}
