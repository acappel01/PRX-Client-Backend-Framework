<?php

namespace Tests\Feature\Api\V1\Patient;

use App\Models\Patient;
use App\Services\PrescribeRx\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two properties the patient-portal proxy exists to guarantee:
 * nothing PRX sends reaches the browser unfiltered, and no id the browser
 * sends reaches PRX unproven.
 *
 * Both are asserted against payloads shaped like PRX's real ones. The
 * encounter and order fixtures below carry the actual column names from
 * prx-demo@07969f8's migrations, because those two endpoints return raw
 * Eloquent models and the whole table goes on the wire.
 */
class PortalLeakAndOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function actingAsPatient(): Patient
    {
        $patient = Patient::factory()->withPrxChart()->create();
        Sanctum::actingAs($patient, ['*']);

        return $patient;
    }

    /** Stub the token mint so every test below reaches its own PRX call. */
    private function mockPrx(callable $expectations): void
    {
        $this->mock(Client::class, function ($mock) use ($expectations): void {
            $mock->shouldReceive('issuePatientToken')->andReturn([
                'token' => 'patient-token',
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ]);
            $expectations($mock);
        });
    }

    // ── Leak ─────────────────────────────────────────────────────────────

    public function test_the_encounter_video_credential_never_reaches_the_browser(): void
    {
        $this->actingAsPatient();

        $this->mockPrx(fn ($mock) => $mock->shouldReceive('getPatientEncounters')->andReturn([[
            'id' => 'enc-1',
            'encounter_number' => 'ENC-1',
            'status' => 'pending_provider_review',
            'scheduled_at' => '2026-09-08T09:00:00Z',
            // Everything below is on the real row and must be dropped.
            'video_room_token' => 'eyJhbGciOiJIUzI1NiJ9.LIVE-TWILIO-JWT',
            'video_room_name' => 'room-abc',
            'encounter_data' => ['answers' => ['q1' => 'yes']],
            'patient_intake_snapshot' => ['weight' => 197],
            'provider_intake_snapshot' => ['note' => 'internal'],
            'soap_note_snapshot' => ['assessment' => 'clinical text'],
            'treatment_plan_snapshot' => ['plan' => 'internal'],
            'client_id' => 'client-uuid',
            'sales_organization_id' => 'org-uuid',
            'provider_profile_id' => 'provider-uuid',
            'is_locked' => false,
            'queue_position' => 3,
            'deleted_by' => null,
        ]]));

        $response = $this->getJson('/api/v1/patient/encounters');

        $response->assertOk();

        $body = $response->json('data.0');

        $this->assertSame('ENC-1', $body['encounter_number'], 'The useful fields must survive.');

        foreach ([
            'video_room_token', 'video_room_name', 'encounter_data',
            'patient_intake_snapshot', 'provider_intake_snapshot',
            'soap_note_snapshot', 'treatment_plan_snapshot',
            'client_id', 'sales_organization_id', 'provider_profile_id',
            'is_locked', 'queue_position', 'deleted_by',
        ] as $leak) {
            $this->assertArrayNotHasKey($leak, $body, "[{$leak}] leaked to the browser.");
        }
    }

    public function test_order_margin_and_gateway_ids_never_reach_the_browser(): void
    {
        $this->actingAsPatient();

        $this->mockPrx(fn ($mock) => $mock->shouldReceive('getPatientOrders')->andReturn([[
            'id' => 'ord-1',
            'order_number' => 'ORD-9373625265',
            'grand_total' => '194.00',
            'workflow_status' => 3,
            'tracking_number' => '1Z999',
            // Ours and the merchant's, never the patient's.
            'profit_margin' => '88.40',
            'total_cost' => '105.60',
            'actual_shipping_cost' => '7.20',
            'gateway_transaction_id' => 'txn_live_1234',
            'authorization_transaction_id' => 'auth_5678',
            'idempotency_key' => 'idem-abc',
            'merchant_account_id' => 'merch-1',
            'commission_report_id' => 'comm-1',
            'federation_provider_snapshot' => ['npi' => '1234567890'],
            'fulfillment_notes' => 'internal note',
            'client_id' => 'client-uuid',
        ]]));

        $body = $this->getJson('/api/v1/patient/orders')->assertOk()->json('data.0');

        $this->assertSame('ORD-9373625265', $body['order_number']);

        foreach ([
            'profit_margin', 'total_cost', 'actual_shipping_cost',
            'gateway_transaction_id', 'authorization_transaction_id',
            'idempotency_key', 'merchant_account_id', 'commission_report_id',
            'federation_provider_snapshot', 'fulfillment_notes', 'client_id',
        ] as $leak) {
            $this->assertArrayNotHasKey($leak, $body, "[{$leak}] leaked to the browser.");
        }
    }

    public function test_a_new_prx_field_is_dropped_rather_than_forwarded(): void
    {
        // The allowlist's whole purpose: PRX adds a column, and it does NOT
        // appear on our wire until someone deliberately adds it to the spec.
        $this->actingAsPatient();

        $this->mockPrx(fn ($mock) => $mock->shouldReceive('getPatientOrders')->andReturn([[
            'id' => 'ord-1',
            'order_number' => 'ORD-1',
            'some_field_invented_next_quarter' => 'surprise',
        ]]));

        $body = $this->getJson('/api/v1/patient/orders')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('some_field_invented_next_quarter', $body);
    }

    // ── Ownership ────────────────────────────────────────────────────────

    public function test_booking_refuses_an_encounter_the_patient_does_not_own(): void
    {
        // The cross-patient write. PRX's own gate passes here because our proxy
        // authenticates as the sales ORG, so the refusal has to happen locally.
        $this->actingAsPatient();

        $this->mockPrx(function ($mock): void {
            $mock->shouldReceive('findPatientEncounter')->andReturn(null); // 404 under the patient scope
            $mock->shouldNotReceive('createAppointment');                  // must never be reached
        });

        $this->postJson('/api/v1/patient/scheduling/appointments', [
            'encounter_type_id' => '019d2842-0000-4000-8000-00000000abcd',
            'provider_profile_id' => '019d2842-0000-4000-8000-00000000bbbb',
            'scheduled_start' => now()->addDay()->toIso8601String(),
            'encounter_id' => '019d2842-0000-4000-8000-00000000dead',
        ])->assertForbidden();
    }

    public function test_booking_never_forwards_caller_supplied_tenancy_or_chart_ids(): void
    {
        $patient = $this->actingAsPatient();
        $sent = null;

        $this->mockPrx(function ($mock) use (&$sent, $patient): void {
            $mock->shouldReceive('findPatientEncounter')
                ->andReturn([
                    'id' => '019d2842-0000-4000-8000-00000000c0de',
                    'patient_chart_id' => $patient->prx_patient_chart_id,
                ]);
            $mock->shouldReceive('createAppointment')
                ->andReturnUsing(function ($payload) use (&$sent) {
                    $sent = $payload;

                    return ['id' => 'appt-1', 'appointment_number' => 'APT-1'];
                });
        });

        $this->postJson('/api/v1/patient/scheduling/appointments', [
            'encounter_type_id' => '019d2842-0000-4000-8000-00000000abcd',
            'provider_profile_id' => '019d2842-0000-4000-8000-00000000bbbb',
            'scheduled_start' => now()->addDay()->toIso8601String(),
            'encounter_id' => '019d2842-0000-4000-8000-00000000c0de',
            // All of the following are honoured by PRX and must be stripped.
            'patient_chart_id' => 'someone-elses-chart',
            'client_id' => 'attacker-client',
            'sales_organization_id' => 'attacker-org',
            'duration_minutes' => 120,
            'provider_timezone' => 'UTC',
        ])->assertCreated();

        $this->assertSame($patient->prx_patient_chart_id, $sent['patient_chart_id']);
        $this->assertArrayNotHasKey('client_id', $sent);
        $this->assertArrayNotHasKey('sales_organization_id', $sent);
        $this->assertArrayNotHasKey('duration_minutes', $sent);
        $this->assertArrayNotHasKey('provider_timezone', $sent);
    }

    public function test_slots_pins_the_chart_id_to_the_session(): void
    {
        // This endpoint 422'd on every call before: patient_chart_id is required
        // by PRX and we sent none.
        $patient = $this->actingAsPatient();
        $sent = null;

        $this->mockPrx(function ($mock) use (&$sent): void {
            $mock->shouldReceive('getAvailabilitySlots')
                ->andReturnUsing(function ($filters) use (&$sent) {
                    $sent = $filters;

                    return ['total_slots' => 0, 'slots' => []];
                });
        });

        $this->getJson('/api/v1/patient/scheduling/slots?'.http_build_query([
            'encounter_type_id' => '019d2842-0000-4000-8000-00000000abcd',
            'patient_chart_id' => 'someone-elses-chart',
            'provider_profile_id' => 'pin-me-to-one-provider',
        ]))->assertOk();

        $this->assertSame($patient->prx_patient_chart_id, $sent['patient_chart_id']);
        $this->assertArrayNotHasKey('provider_profile_id', $sent);
    }

    public function test_booking_refuses_an_encounter_belonging_to_another_chart(): void
    {
        // The probe returning *something* is not proof. PRX resolves "the user's
        // chart" as the first patient_charts row for that user, and a patient
        // re-linked by email across organisations can hold more than one — so
        // the chart id has to be compared, not merely present.
        $this->actingAsPatient();

        $this->mockPrx(function ($mock): void {
            $mock->shouldReceive('findPatientEncounter')->andReturn([
                'id' => '019d2842-0000-4000-8000-00000000c0de',
                'patient_chart_id' => 'a-different-chart',
            ]);
            $mock->shouldNotReceive('createAppointment');
        });

        $this->postJson('/api/v1/patient/scheduling/appointments', [
            'encounter_type_id' => '019d2842-0000-4000-8000-00000000abcd',
            'provider_profile_id' => '019d2842-0000-4000-8000-00000000bbbb',
            'scheduled_start' => now()->addDay()->toIso8601String(),
            'encounter_id' => '019d2842-0000-4000-8000-00000000c0de',
        ])->assertForbidden();
    }

    public function test_an_empty_probe_response_is_not_mistaken_for_ownership(): void
    {
        // extractData() returns [] for a 2xx carrying no `data`. A `!== null`
        // check would have accepted that as proof.
        $this->actingAsPatient();

        $this->mockPrx(function ($mock): void {
            $mock->shouldReceive('findPatientEncounter')->andReturn([]);
            $mock->shouldNotReceive('createAppointment');
        });

        $this->postJson('/api/v1/patient/scheduling/appointments', [
            'encounter_type_id' => '019d2842-0000-4000-8000-00000000abcd',
            'provider_profile_id' => '019d2842-0000-4000-8000-00000000bbbb',
            'scheduled_start' => now()->addDay()->toIso8601String(),
            'encounter_id' => '019d2842-0000-4000-8000-00000000c0de',
        ])->assertForbidden();
    }

    public function test_an_unlinked_account_cannot_schedule_at_all(): void
    {
        $patient = Patient::factory()->create(); // no PRX chart
        Sanctum::actingAs($patient, ['*']);

        $this->mockPrx(fn ($mock) => $mock->shouldNotReceive('getAvailabilitySlots'));

        $this->getJson('/api/v1/patient/scheduling/slots?encounter_type_id=019d2842-0000-4000-8000-00000000abcd')
            ->assertStatus(409);
    }

    /**
     * Every patient-token screen, not only the three that checked for
     * themselves: minting a token for an unlinked account threw, which was a
     * 500 and an ERROR log line per screen an unlinked patient opened.
     */
    public function test_an_unlinked_account_gets_the_connect_your_record_answer_from_every_clinical_read(): void
    {
        Sanctum::actingAs(Patient::factory()->create(), ['*']); // no PRX chart

        // Not even the token mint may be attempted.
        $this->mock(Client::class, fn ($mock) => $mock->shouldNotReceive('issuePatientToken'));

        $conversation = '9f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f';
        $encounter = '01a07dfe-6307-727b-8ef4-acddfeebcafa';

        foreach ([
            'dashboard', 'encounters', 'vitals', 'profile', 'vitals/goals', 'orders', 'prescriptions',
            'conversations', "conversations/{$conversation}/messages", "encounters/{$encounter}/requirements",
            "encounters/{$encounter}/video-token", 'health/series',
        ] as $path) {
            $this->getJson("/api/v1/patient/{$path}")
                ->assertStatus(409)
                ->assertJsonPath('code', 'no_linked_chart');
        }
    }
}
