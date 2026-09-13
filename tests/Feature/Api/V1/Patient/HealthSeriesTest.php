<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Models\Patient;
use App\Services\PrescribeRx\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /patient/health/series — the Health screen's one call.
 *
 * Fixtures use the field names `PatientSelfServiceController::formatVital`
 * returns (prx-demo develop), newest first as the provider sends them.
 */
class HealthSeriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-13 12:00:00');

        Sanctum::actingAs(Patient::factory()->withPrxChart()->create(), ['*']);
    }

    private function vital(string $at, array $overrides = []): array
    {
        return array_merge([
            'id' => 'vit-'.$at,
            'weight' => null,
            'systolic_bp' => null,
            'diastolic_bp' => null,
            'heart_rate' => null,
            'blood_glucose' => null,
            'internal_note' => 'must not leave',
            'created_at' => $at,
        ], $overrides);
    }

    private function mockPrx(array $vitals, array $goals = ['goal_weight' => null, 'goal_date' => null]): void
    {
        $this->mock(Client::class, function ($mock) use ($vitals, $goals): void {
            $mock->shouldReceive('issuePatientToken')->andReturn(['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()]);
            $mock->shouldReceive('getPatientVitals')->with('patient-token', ['limit' => 1000])->once()->andReturn($vitals);
            $mock->shouldReceive('getVitalsGoals')->andReturn($goals);
        });
    }

    public function test_a_year_is_the_default_and_series_read_oldest_first(): void
    {
        $this->mockPrx([
            $this->vital('2026-09-10T08:00:00+00:00', ['weight' => 196.4]),
            $this->vital('2026-03-01T08:00:00+00:00', ['weight' => 205]),
            $this->vital('2025-06-01T08:00:00+00:00', ['weight' => 230]), // older than a year
        ], ['goal_weight' => 185, 'goal_date' => '2026-12-12']);

        $data = $this->getJson('/api/v1/patient/health/series')->assertOk()->json('data');

        $this->assertSame('1year', $data['period']);
        $this->assertSame([
            ['at' => '2026-03-01T08:00:00+00:00', 'value' => 205],
            ['at' => '2026-09-10T08:00:00+00:00', 'value' => 196.4],
        ], $data['series']['weight']);
        $this->assertSame(['weight' => 185, 'date' => '2026-12-12'], $data['goal']);
        // The list is not period-scoped and keeps the provider's newest-first order.
        $this->assertCount(3, $data['items']);
        $this->assertSame('2026-09-10T08:00:00+00:00', $data['items'][0]['created_at']);
        $this->assertArrayNotHasKey('internal_note', $data['items'][0]);
        $this->assertFalse($data['truncated']);
    }

    public function test_all_time_keeps_readings_older_than_a_year(): void
    {
        $this->mockPrx([
            $this->vital('2026-09-10T08:00:00+00:00', ['weight' => 196.4]),
            $this->vital('2024-01-01T08:00:00+00:00', ['weight' => 240]),
        ]);

        $weight = $this->getJson('/api/v1/patient/health/series?period=all')->assertOk()->json('data.series.weight');

        $this->assertSame([240, 196.4], array_column($weight, 'value'));
    }

    public function test_each_measurement_gets_its_own_series_and_half_a_blood_pressure_is_not_plotted(): void
    {
        $this->mockPrx([
            $this->vital('2026-09-10T08:00:00+00:00', ['systolic_bp' => 122, 'diastolic_bp' => 81, 'heart_rate' => 64]),
            $this->vital('2026-09-09T08:00:00+00:00', ['systolic_bp' => 130]),
            $this->vital('2026-09-08T08:00:00+00:00', ['blood_glucose' => 91]),
            $this->vital('not-a-date', ['weight' => 200]),
        ]);

        $series = $this->getJson('/api/v1/patient/health/series')->assertOk()->json('data.series');

        $this->assertSame([['at' => '2026-09-10T08:00:00+00:00', 'systolic' => 122, 'diastolic' => 81]], $series['blood_pressure']);
        $this->assertSame([64], array_column($series['heart_rate'], 'value'));
        $this->assertSame([91], array_column($series['blood_glucose'], 'value'));
        // A reading with no usable time cannot be placed on a time axis.
        $this->assertSame([], $series['weight']);
    }

    public function test_a_full_page_from_the_provider_is_reported_as_truncated(): void
    {
        $vitals = [];
        for ($i = 0; $i < 1000; $i++) {
            $vitals[] = $this->vital(now()->subHours($i)->toIso8601String(), ['weight' => 200]);
        }
        $this->mockPrx($vitals);

        $data = $this->getJson('/api/v1/patient/health/series')->assertOk()->json('data');

        $this->assertTrue($data['truncated']);
        $this->assertSame(1000, $data['readings_considered']);
        $this->assertCount(50, $data['items']);
    }

    public function test_an_unknown_period_is_refused_before_the_provider(): void
    {
        $this->mock(Client::class, fn ($mock) => $mock->shouldNotReceive('getPatientVitals'));

        $this->getJson('/api/v1/patient/health/series?period=5years')->assertJsonValidationErrors('period');
    }
}
