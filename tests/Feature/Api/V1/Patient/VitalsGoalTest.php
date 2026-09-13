<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Models\Patient;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The weight goal — PRX's `/me/patient/vitals/goals` (GET, PUT). */
class VitalsGoalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('prescribe-rx.stub', false);
        $settings = app(IntegrationSettings::class);
        $settings->prescribe_rx_enabled = true;
        $settings->prescribe_rx_api_token = 'test-org-token';
        $settings->save();

        Sanctum::actingAs(Patient::factory()->withPrxChart()->create(), ['*']);

        Http::fake([
            '*/patients/*/issue-token' => Http::response(['data' => ['token' => 'patient-token', 'expires_at' => now()->addMinutes(30)->toIso8601String()]], 201),
            '*/me/patient/vitals/goals' => fn (HttpRequest $request) => Http::response(['success' => true, 'data' => [
                'goal_weight' => $request->method() === 'PUT' ? $request->data()['goal_weight'] : 180,
                'goal_date' => $request->method() === 'PUT' ? ($request->data()['goal_date'] ?? null) : null,
                'settings' => ['secret' => 'x'],
            ]]),
        ]);
    }

    public function test_reading_the_goal_keeps_only_the_goal(): void
    {
        $response = $this->getJson('/api/v1/patient/vitals/goals')
            ->assertOk()
            ->assertJsonPath('data.goal_weight', 180)
            ->assertJsonPath('data.goal_date', null);

        $this->assertArrayNotHasKey('settings', $response->json('data'));
    }

    public function test_setting_the_goal_puts_exactly_the_validated_values_upstream(): void
    {
        $date = now()->addMonths(3)->toDateString();

        $this->postJson('/api/v1/patient/vitals/goals', ['goal_weight' => '172.46', 'goal_date' => $date, 'patient_chart_id' => 'someone-else'])
            ->assertOk()
            ->assertJsonPath('data.goal_weight', 172.5)
            ->assertJsonPath('data.goal_date', $date);

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), '/vitals/goals'))->sole();
        $this->assertSame('PUT', $sent->method());
        $this->assertSame(['goal_weight' => 172.5, 'goal_date' => $date], $sent->data());
    }

    /** assertNotSent: a faked upstream would otherwise accept anything. */
    public function test_out_of_range_values_never_reach_the_provider(): void
    {
        $this->postJson('/api/v1/patient/vitals/goals', ['goal_weight' => 60])->assertJsonValidationErrors('goal_weight');
        $this->postJson('/api/v1/patient/vitals/goals', ['goal_weight' => 170, 'goal_date' => now()->subDay()->toDateString()])->assertJsonValidationErrors('goal_date');
        $this->postJson('/api/v1/patient/vitals/goals', [])->assertJsonValidationErrors('goal_weight');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/vitals/goals'));
    }
}
