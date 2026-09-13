<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Actions\Patient\IssuePortalTokenAction;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Models\Patient;
use App\Models\User;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The provider's identifiers are kept on our record; demographics are read live
 * (operator decision 2026-09-13).
 */
class ProviderIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    private const CHART = '01a07ea4-282e-70d1-b350-6fc58731c738';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('prescribe-rx.stub', false);
        $settings = app(IntegrationSettings::class);
        $settings->prescribe_rx_enabled = true;
        $settings->prescribe_rx_api_token = 'test-org-token';
        $settings->save();
    }

    private function chart(): array
    {
        return [
            'id' => self::CHART, 'patient_number' => 'P-100245', 'patient_id' => 'prx-user-1',
            'first_name' => 'Atlasfour', 'middle_name' => null, 'last_name' => 'Atlas-Four',
            'dob' => '1985-04-02', 'email' => 'atlasfour@prescribe-rx.com', 'phone' => '5551234567',
            'mrn' => 'SECRET-MRN', 'drivers_license_number' => 'SECRET-DL',
        ];
    }

    private function fakePrx(int $chartStatus = 200): void
    {
        Http::fake([
            '*/patients/*/issue-token' => Http::response(['data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String(), 'patient_id' => 'prx-user-1', 'patient_chart_id' => self::CHART]], 201),
            '*/me/patient' => Http::response(['success' => true, 'data' => $this->chart()], $chartStatus),
            '*/patients/'.self::CHART => Http::response(['success' => true, 'data' => $this->chart()], $chartStatus),
        ]);
    }

    public function test_minting_a_token_records_the_providers_patient_id_and_number(): void
    {
        $patient = Patient::factory()->create(['prx_patient_chart_id' => self::CHART, 'prx_patient_id' => null]);
        $this->fakePrx();

        app(IssuePortalTokenAction::class)->execute($patient);

        $fresh = $patient->fresh();
        $this->assertSame('prx-user-1', $fresh->prx_patient_id);
        $this->assertSame('P-100245', $fresh->prx_patient_number);
        // Identifiers only: nothing demographic was copied.
        $this->assertNotSame('1985-04-02', $fresh->date_of_birth?->toDateString());
        $this->assertNotSame('Atlasfour', $fresh->first_name);
    }

    public function test_a_patient_with_a_number_is_not_looked_up_again(): void
    {
        $patient = Patient::factory()->create(['prx_patient_chart_id' => self::CHART, 'prx_patient_id' => 'prx-user-1', 'prx_patient_number' => 'P-100245']);
        $this->fakePrx();

        app(IssuePortalTokenAction::class)->execute($patient);

        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/me/patient'));
    }

    public function test_a_failed_lookup_never_costs_the_patient_their_token(): void
    {
        $patient = Patient::factory()->create(['prx_patient_chart_id' => self::CHART]);
        $this->fakePrx(500);

        $this->assertSame('patient-token', app(IssuePortalTokenAction::class)->execute($patient));
        $this->assertNull($patient->fresh()->prx_patient_number);
    }

    public function test_the_patient_sees_their_details_live_and_nothing_else(): void
    {
        $patient = Patient::factory()->create(['prx_patient_chart_id' => self::CHART]);
        Sanctum::actingAs($patient, ['*']);
        $this->fakePrx();

        $response = $this->getJson('/api/v1/patient/profile')
            ->assertOk()
            ->assertJsonPath('data.patient_number', 'P-100245')
            ->assertJsonPath('data.dob', '1985-04-02')
            ->assertJsonPath('data.last_name', 'Atlas-Four');

        foreach (['SECRET-MRN', 'SECRET-DL', 'prx-user-1'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_operators_see_the_providers_details_live_on_the_patient_record(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $operator = User::factory()->create()->refresh();
        $operator->assignRole('super_admin');
        $this->actingAs($operator);
        $patient = Patient::factory()->create(['prx_patient_chart_id' => self::CHART, 'prx_patient_number' => 'P-100245']);
        $this->fakePrx();

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->assertSee('As the clinical provider holds it')
            ->assertSee('Atlasfour Atlas-Four')
            ->assertSee('1985-04-02')
            ->assertSee('P-100245');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/patients/'.self::CHART) && $r->header('Authorization') === ['Bearer test-org-token']);
    }

    public function test_an_unreachable_provider_shows_placeholders_not_an_error(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $operator = User::factory()->create()->refresh();
        $operator->assignRole('super_admin');
        $this->actingAs($operator);
        $patient = Patient::factory()->create(['prx_patient_chart_id' => self::CHART]);
        $this->fakePrx(500);

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->assertOk()
            ->assertSee('Could not load from the provider');
    }
}
