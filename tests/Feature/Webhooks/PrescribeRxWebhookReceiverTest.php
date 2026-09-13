<?php

namespace Tests\Feature\Webhooks;

use App\Enums\EncounterStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\Webhooks\InboundWebhookStatus;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderShipment;
use App\Models\InboundWebhookEvent;
use App\Models\Lead;
use App\Services\PrescribeRx\Webhooks\PrescribeRxWebhookHandler;
use App\Settings\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The prescribe-rx receiver, end to end: signature → ledger → job → handler.
 *
 * Envelopes here are copied from their sender (prx-demo `WebhookDispatchService`
 * + `WebhookPayloadBuilder`), not invented — the receivers this replaced were
 * tested against a shape prescribe-rx never sends, and passed.
 */
class PrescribeRxWebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationSettings::class)->prescribe_rx_webhook_secret = self::SECRET;
    }

    // ── Signature and the ledger ─────────────────────────────────────────

    public function test_an_unsigned_request_is_rejected_and_nothing_is_recorded(): void
    {
        $this->postJson('/api/webhooks/prescribe-rx', $this->envelope('webhook.test'))->assertUnauthorized();

        $this->assertSame(0, InboundWebhookEvent::count());
    }

    public function test_a_wrong_signature_is_rejected_and_nothing_is_recorded(): void
    {
        $this->deliver($this->envelope('webhook.test'), secret: 'someone-else')->assertUnauthorized();

        $this->assertSame(0, InboundWebhookEvent::count());
    }

    public function test_it_fails_closed_when_no_secret_is_configured(): void
    {
        app(IntegrationSettings::class)->prescribe_rx_webhook_secret = null;

        $this->deliver($this->envelope('webhook.test'))->assertStatus(503);

        $this->assertSame(0, InboundWebhookEvent::count());
    }

    public function test_the_old_v1_receiver_is_gone(): void
    {
        $this->deliver($this->envelope('webhook.test'), uri: '/api/v1/webhooks/prescribe-rx')->assertNotFound();
    }

    public function test_a_signed_test_event_is_recorded_and_ignored(): void
    {
        $this->deliver($this->envelope('webhook.test', ['message' => 'This is a test webhook delivery from PrescribeRx.']))
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => false]);

        $event = InboundWebhookEvent::sole();
        $this->assertSame('prescribe-rx', $event->source);
        $this->assertSame(InboundWebhookStatus::Ignored, $event->status);
        $this->assertSame(64, strlen($event->payload_hash));
    }

    public function test_a_signed_body_without_an_event_is_a_400_and_not_recorded(): void
    {
        $this->deliver(['data' => ['encounter_id' => 'x']])->assertStatus(400);

        $this->assertSame(0, InboundWebhookEvent::count());
    }

    public function test_a_redelivery_of_the_same_webhook_id_is_acknowledged_once(): void
    {
        $encounter = Encounter::factory()->create(['status' => EncounterStatus::Submitted]);
        $envelope = $this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'old_status' => 'unassigned',
            'new_status' => 'pending_provider_review',
        ]);

        $this->deliver($envelope)->assertOk()->assertJson(['duplicate' => false]);
        $this->deliver($envelope)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, InboundWebhookEvent::count());
    }

    public function test_dedupe_reads_the_signed_body_not_the_unsigned_delivery_header(): void
    {
        $envelope = $this->envelope('webhook.test');

        $this->deliver($envelope, headers: ['X-Webhook-ID' => (string) Str::uuid()])->assertOk();
        $this->deliver($envelope, headers: ['X-Webhook-ID' => (string) Str::uuid()])->assertJson(['duplicate' => true]);

        $this->assertSame(1, InboundWebhookEvent::count());
    }

    public function test_free_text_typed_by_clinic_staff_is_never_stored(): void
    {
        $this->deliver($this->envelope('encounter.cancelled', [
            'encounter_id' => (string) Str::uuid(),
            'encounter_number' => 'ENC-1',
            'reason' => 'Patient disclosed a contraindication',
            'provider_name' => 'Dr Example',
            'cancelled_at' => now()->toIso8601String(),
        ]))->assertOk();

        $payload = InboundWebhookEvent::sole()->payload;
        $this->assertArrayNotHasKey('reason', $payload);
        $this->assertArrayNotHasKey('provider_name', $payload);
        $this->assertSame('ENC-1', $payload['encounter_number']);
    }

    // ── Encounters: update-only ──────────────────────────────────────────

    public function test_a_status_change_updates_the_encounter_our_checkout_created_and_keeps_its_lead(): void
    {
        $lead = Lead::factory()->create();
        $encounter = Encounter::factory()->create(['lead_id' => $lead->id, 'status' => EncounterStatus::Submitted]);

        $this->deliver($this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'patient_chart_id' => $encounter->prescribe_rx_patient_id,
            'old_status' => 'unassigned',
            'new_status' => 'pending_provider_review',
        ]))->assertOk();

        $fresh = $encounter->fresh();
        $this->assertSame(EncounterStatus::InReview, $fresh->status);
        $this->assertSame('pending_provider_review', $fresh->provider_status);
        $this->assertSame($lead->id, $fresh->lead_id);

        $event = InboundWebhookEvent::sole();
        $this->assertSame(InboundWebhookStatus::Processed, $event->status);
        $this->assertSame($encounter->id, $event->matched_id);
    }

    public function test_an_event_for_an_encounter_we_do_not_hold_creates_nothing(): void
    {
        $lead = Lead::factory()->create(['prescribe_rx_encounter_id' => 'enc-from-an-anonymous-endpoint']);

        $this->deliver($this->envelope('encounter.created', [
            'encounter_id' => 'enc-from-an-anonymous-endpoint',
            'patient_chart_id' => (string) Str::uuid(),
            'status' => 'pending_intake',
        ]))->assertOk();

        $this->assertSame(0, Encounter::withTrashed()->count());
        $this->assertSame(InboundWebhookStatus::Unmatched, InboundWebhookEvent::sole()->status);
        $this->assertNull($lead->fresh()->patient_id);
    }

    public function test_an_unknown_provider_status_is_stored_but_does_not_move_our_status(): void
    {
        $encounter = Encounter::factory()->create(['status' => EncounterStatus::InReview]);

        $this->deliver($this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'new_status' => 'some_state_added_next_year',
        ]))->assertOk();

        $fresh = $encounter->fresh();
        $this->assertSame(EncounterStatus::InReview, $fresh->status);
        $this->assertSame('some_state_added_next_year', $fresh->provider_status);
    }

    public function test_an_older_event_never_overwrites_a_newer_status(): void
    {
        $encounter = Encounter::factory()->create();

        $this->deliver($this->envelope('encounter.prescribed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
        ], at: now()))->assertOk();

        $this->deliver($this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'new_status' => 'pending_provider_review',
        ], at: now()->subMinutes(5)))->assertOk();

        $fresh = $encounter->fresh();
        $this->assertSame('prescribed', $fresh->provider_status);
        $this->assertSame(EncounterStatus::Approved, $fresh->status);
    }

    public function test_completion_stamps_completed_at_once(): void
    {
        $encounter = Encounter::factory()->create();
        $first = now()->subHour()->startOfSecond();

        $this->deliver($this->envelope('encounter.completed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'completed_at' => $first->toIso8601String(),
        ], at: now()->subHour()))->assertOk();

        $this->deliver($this->envelope('encounter.completed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'completed_at' => now()->toIso8601String(),
        ]))->assertOk();

        $fresh = $encounter->fresh();
        $this->assertSame(EncounterStatus::Completed, $fresh->status);
        $this->assertTrue($fresh->completed_at->equalTo($first));
    }

    public function test_a_chart_id_that_disagrees_with_ours_is_not_taken(): void
    {
        $encounter = Encounter::factory()->create();
        $ours = $encounter->prescribe_rx_patient_id;

        $this->deliver($this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'patient_chart_id' => (string) Str::uuid(),
            'new_status' => 'prescribed',
        ]))->assertOk();

        $this->assertSame($ours, $encounter->fresh()->prescribe_rx_patient_id);
    }

    // ── Orders ───────────────────────────────────────────────────────────

    public function test_the_first_order_event_finds_the_checkout_order_through_its_encounter_and_backfills_the_id(): void
    {
        $encounter = Encounter::factory()->create();
        $order = Order::factory()->create([
            'encounter_id' => $encounter->id,
            'prescribe_rx_order_id' => null,
            'prescribe_rx_order_number' => null,
            'status' => OrderStatus::Pending,
        ]);

        // Integer-coded, as their casts send them: 3 = ready_for_fulfillment,
        // 2 = captured, 0 = none.
        $this->deliver($this->envelope('order.status_changed', [
            'order_id' => 'prx-order-1',
            'order_number' => 'ORD-1001',
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'changed_fields' => ['workflow_status'],
            'workflow_status' => 3,
            'payment_status' => 2,
            'shipping_status' => 0,
        ]))->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('prx-order-1', $fresh->prescribe_rx_order_id);
        $this->assertSame('ORD-1001', $fresh->prescribe_rx_order_number);
        $this->assertSame('ready_for_fulfillment', $fresh->provider_workflow_status);
        $this->assertSame('captured', $fresh->provider_payment_status);
        $this->assertSame('none', $fresh->provider_shipping_status);
        $this->assertSame(OrderStatus::Processing, $fresh->status);
    }

    public function test_delivered_shipping_status_marks_the_order_delivered_once(): void
    {
        $order = Order::factory()->create(['prescribe_rx_order_id' => 'prx-order-2', 'delivered_at' => null]);

        $this->deliver($this->envelope('order.status_changed', [
            'order_id' => 'prx-order-2', 'workflow_status' => 5, 'payment_status' => 7, 'shipping_status' => 4,
        ]))->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Delivered, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_cancellation_sets_cancelled_at_once(): void
    {
        $original = now()->subDay()->startOfSecond();
        $order = Order::factory()->create(['prescribe_rx_order_id' => 'prx-order-3', 'cancelled_at' => $original]);

        $this->deliver($this->envelope('order.cancelled', [
            'order_id' => 'prx-order-3', 'reason' => 'typed by staff', 'cancelled_at' => now()->toIso8601String(),
        ]))->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertTrue($fresh->cancelled_at->equalTo($original));
    }

    public function test_an_event_for_an_order_we_do_not_hold_creates_nothing(): void
    {
        $this->deliver($this->envelope('order.placed', [
            'order_id' => 'prx-unknown', 'encounter_id' => 'enc-unknown', 'workflow_status' => 0,
        ]))->assertOk();

        $this->assertSame(0, Order::withTrashed()->count());
        $this->assertSame(InboundWebhookStatus::Unmatched, InboundWebhookEvent::sole()->status);
    }

    // ── Fulfillment ──────────────────────────────────────────────────────

    public function test_shipped_then_delivered_tracks_one_shipment_by_tracking_number(): void
    {
        $order = Order::factory()->create(['prescribe_rx_order_id' => 'prx-order-4', 'shipped_at' => null]);
        $shipped = [
            'order_id' => 'prx-order-4',
            'tracking_number' => '9400111899223418527401',
            'tracking_url' => 'https://tracking.example/9400111899223418527401',
            'carrier' => 'USPS',
            'shipped_at' => now()->subDay()->toIso8601String(),
        ];

        $this->deliver($this->envelope('fulfillment.shipped', $shipped))->assertOk();
        $this->deliver($this->envelope('fulfillment.shipped', $shipped))->assertOk();
        $this->deliver($this->envelope('fulfillment.delivered', [
            'order_id' => 'prx-order-4',
            'tracking_number' => '9400111899223418527401',
            'delivered_at' => now()->toIso8601String(),
        ]))->assertOk();

        $shipment = OrderShipment::sole();
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('USPS', $shipment->carrier);
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertNotNull($shipment->delivered_at);
        $this->assertNotNull($order->fresh()->shipped_at);
    }

    public function test_fulfillment_for_an_order_we_do_not_hold_creates_nothing(): void
    {
        $this->deliver($this->envelope('fulfillment.shipped', [
            'order_id' => 'prx-unknown', 'tracking_number' => 'T1',
        ]))->assertOk();

        $this->assertSame(0, OrderShipment::count());
        $this->assertSame(InboundWebhookStatus::Unmatched, InboundWebhookEvent::sole()->status);
    }

    // ── Failure, replay, retention ───────────────────────────────────────

    public function test_an_event_with_no_registered_handler_is_marked_failed_and_replayable(): void
    {
        config()->set('webhooks.handlers', []);
        $encounter = Encounter::factory()->create();

        $this->deliver($this->envelope('encounter.prescribed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
        ]))->assertOk();

        $event = InboundWebhookEvent::sole();
        $this->assertSame(InboundWebhookStatus::Failed, $event->status);
        $this->assertNotNull($event->error);

        config()->set('webhooks.handlers', ['prescribe-rx' => PrescribeRxWebhookHandler::class]);

        $this->artisan('webhooks:replay', ['--status' => 'failed'])->assertSuccessful();

        $this->assertSame(InboundWebhookStatus::Processed, $event->fresh()->status);
        $this->assertSame(EncounterStatus::Approved, $encounter->fresh()->status);
    }

    public function test_an_unmatched_event_applies_on_replay_once_the_record_exists(): void
    {
        $this->deliver($this->envelope('encounter.prescribed', ['encounter_id' => 'enc-late']))->assertOk();
        $this->assertSame(InboundWebhookStatus::Unmatched, InboundWebhookEvent::sole()->status);

        $encounter = Encounter::factory()->create(['prescribe_rx_encounter_id' => 'enc-late']);
        $this->artisan('webhooks:replay')->assertSuccessful();

        $this->assertSame(InboundWebhookStatus::Processed, InboundWebhookEvent::sole()->status);
        $this->assertSame(EncounterStatus::Approved, $encounter->fresh()->status);
    }

    public function test_pruning_removes_old_settled_events_and_keeps_failed_ones(): void
    {
        foreach ([InboundWebhookStatus::Processed, InboundWebhookStatus::Unmatched, InboundWebhookStatus::Failed] as $status) {
            $event = InboundWebhookEvent::create([
                'source' => 'prescribe-rx',
                'event_type' => 'webhook.test',
                'payload_hash' => str_repeat('a', 64),
                'dedupe_key' => hash('sha256', $status->value),
                'status' => $status,
            ]);
            $event->forceFill(['created_at' => now()->subDays(InboundWebhookEvent::RETENTION_DAYS + 1)])->save();
        }

        $this->artisan('model:prune', ['--model' => [InboundWebhookEvent::class]])->assertSuccessful();

        $this->assertSame([InboundWebhookStatus::Failed], InboundWebhookEvent::pluck('status')->all());
    }

    // ── Edge cases the review asked for ──────────────────────────────────

    public function test_an_empty_chart_id_is_filled_from_a_signed_event(): void
    {
        $encounter = Encounter::factory()->create(['prescribe_rx_patient_id' => null]);
        $chart = (string) Str::uuid();

        $this->deliver($this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'patient_chart_id' => $chart,
            'new_status' => 'unassigned',
        ]))->assertOk();

        $this->assertSame($chart, $encounter->fresh()->prescribe_rx_patient_id);
    }

    public function test_created_records_the_starting_status_without_moving_the_checkout_status(): void
    {
        $encounter = Encounter::factory()->create(['status' => EncounterStatus::Submitted]);

        $this->deliver($this->envelope('encounter.created', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'status' => 'pending_intake',
        ]))->assertOk();

        $fresh = $encounter->fresh();
        $this->assertSame(EncounterStatus::Submitted, $fresh->status);
        $this->assertSame('pending_intake', $fresh->provider_status);
    }

    public function test_created_arriving_after_a_real_transition_changes_nothing(): void
    {
        $encounter = Encounter::factory()->create();

        $this->deliver($this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'new_status' => 'pending_provider_review',
        ], at: now()->subMinute()))->assertOk();

        // Their `created` timestamp is when the listener ran, so it can be LATER.
        $this->deliver($this->envelope('encounter.created', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'status' => 'pending_intake',
        ], at: now()))->assertOk();

        $fresh = $encounter->fresh();
        $this->assertSame('pending_provider_review', $fresh->provider_status);
        $this->assertSame(EncounterStatus::InReview, $fresh->status);
    }

    public function test_an_early_created_with_a_late_envelope_time_does_not_make_the_next_transition_stale(): void
    {
        $encounter = Encounter::factory()->create();

        // Delivered first, but their listener ran late: envelope time is after
        // the real transition, `created_at` is before it.
        $this->deliver($this->envelope('encounter.created', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'status' => 'pending_intake',
            'created_at' => now()->subMinutes(10)->toIso8601String(),
        ], at: now()))->assertOk();

        $this->deliver($this->envelope('encounter.status_changed', [
            'encounter_id' => $encounter->prescribe_rx_encounter_id,
            'new_status' => 'pending_provider_review',
        ], at: now()->subMinutes(5)))->assertOk();

        $fresh = $encounter->fresh();
        $this->assertSame('pending_provider_review', $fresh->provider_status);
        $this->assertSame(EncounterStatus::InReview, $fresh->status);
    }

    public function test_a_partial_refund_does_not_mark_the_order_refunded_and_a_full_one_does(): void
    {
        $order = Order::factory()->create(['prescribe_rx_order_id' => 'prx-refund', 'status' => OrderStatus::Processing, 'refunded_at' => null]);

        $this->deliver($this->envelope('order.refunded', [
            'order_id' => 'prx-refund', 'refund_amount' => 10.0, 'is_partial' => true, 'payment_status' => 8,
        ], at: now()->subMinute()))->assertOk();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
        $this->assertSame('partially_refunded', $order->fresh()->provider_payment_status);
        $this->assertNull($order->fresh()->refunded_at);

        $this->deliver($this->envelope('order.refunded', [
            'order_id' => 'prx-refund', 'refund_amount' => 99.0, 'is_partial' => false, 'payment_status' => 5,
        ]))->assertOk();

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->refunded_at);
    }

    public function test_a_voided_hold_records_the_payment_status_and_leaves_the_order_status(): void
    {
        $order = Order::factory()->create(['prescribe_rx_order_id' => 'prx-void', 'status' => OrderStatus::Pending]);

        $this->deliver($this->envelope('order.voided', [
            'order_id' => 'prx-void', 'voided_amount' => 50.0, 'payment_status' => 4,
        ]))->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('voided', $fresh->provider_payment_status);
        $this->assertSame(OrderStatus::Pending, $fresh->status);
    }

    public function test_fulfillment_cancelled_updates_an_existing_shipment_and_never_creates_one(): void
    {
        $order = Order::factory()->create(['prescribe_rx_order_id' => 'prx-fc']);
        $shipment = OrderShipment::factory()->create(['order_id' => $order->id, 'tracking_number' => 'T-KNOWN', 'status' => ShipmentStatus::Shipped]);

        $this->deliver($this->envelope('fulfillment.cancelled', ['order_id' => 'prx-fc', 'tracking_number' => 'T-KNOWN']))->assertOk();
        $this->deliver($this->envelope('fulfillment.cancelled', ['order_id' => 'prx-fc', 'tracking_number' => 'T-UNKNOWN']))->assertOk();

        $this->assertSame(ShipmentStatus::Cancelled, $shipment->fresh()->status);
        $this->assertSame(1, OrderShipment::count());
    }

    public function test_a_fulfillment_event_with_no_order_id_is_ignored_not_left_unmatched(): void
    {
        $this->deliver($this->envelope('fulfillment.delivered', ['order_id' => null, 'tracking_number' => 'T1']))->assertOk();

        $this->assertSame(InboundWebhookStatus::Ignored, InboundWebhookEvent::sole()->status);
    }

    public function test_replay_by_uuid_and_dry_run(): void
    {
        $this->deliver($this->envelope('encounter.prescribed', ['encounter_id' => 'enc-dry']))->assertOk();
        $event = InboundWebhookEvent::sole();
        $encounter = Encounter::factory()->create(['prescribe_rx_encounter_id' => 'enc-dry']);

        $this->artisan('webhooks:replay', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(InboundWebhookStatus::Unmatched, $event->fresh()->status);

        $this->artisan('webhooks:replay', ['uuid' => $event->uuid])->assertSuccessful();
        $this->assertSame(InboundWebhookStatus::Processed, $event->fresh()->status);
        $this->assertSame(EncounterStatus::Approved, $encounter->fresh()->status);

        $this->artisan('webhooks:replay', ['--status' => 'processed'])->assertFailed();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $event, array $data = [], ?\DateTimeInterface $at = null): array
    {
        return [
            'event' => $event,
            'timestamp' => ($at ?? now())->format(DATE_ATOM),
            'data' => $data,
            'webhook_id' => (string) Str::uuid(),
            'subscription_id' => (string) Str::uuid(),
        ];
    }

    /**
     * Signed exactly as their `PrescribeRxWebhookSigner` signs:
     * `sha256=` . hmac(json_encode(envelope)), over the body as sent.
     *
     * @param  array<string, mixed>  $envelope
     * @param  array<string, string>  $headers
     */
    private function deliver(array $envelope, string $secret = self::SECRET, string $uri = '/api/webhooks/prescribe-rx', array $headers = []): TestResponse
    {
        $body = json_encode($envelope);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $server['HTTP_X_PRESCRIBERX_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }
}
