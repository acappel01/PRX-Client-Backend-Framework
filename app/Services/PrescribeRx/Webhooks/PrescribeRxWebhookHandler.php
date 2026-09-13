<?php

namespace App\Services\PrescribeRx\Webhooks;

use App\Contracts\Webhooks\InboundWebhookHandler;
use App\Data\Webhooks\InboundWebhookOutcome;
use App\Enums\EncounterStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderShipment;
use App\Models\InboundWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies prescribe-rx encounter, order and fulfillment events to this
 * install's copies of those records.
 *
 * ── UPDATE-ONLY, AND IT NEVER TOUCHES A LEAD ─────────────────────────────────
 *
 * An `Encounter` row is the evidence the patient claim flow accepts
 * (`Lead::scopeClaimableUnder`, `LinkPatientToPrxChartAction`), keyed on
 * `encounters.lead_id`. A webhook carries no lead, and the only lead-side
 * column that names an encounter (`leads.prescribe_rx_encounter_id`) is
 * written by unauthenticated endpoints — resolving a lead through it would let
 * anyone point their lead at a stranger's encounter and claim that chart. So
 * this handler finds rows by the provider's own ids, updates only rows our
 * checkout already created, never creates an encounter or order, and never
 * writes `lead_id`. An event for a record we do not hold is `unmatched`, kept
 * for replay.
 *
 * Shipments are the one insert: a tracking number under an order we already
 * hold. That is order data, and nothing reads it as proof of identity.
 *
 * ── OLDER EVENTS NEVER WIN ───────────────────────────────────────────────────
 *
 * Retries and replays arrive out of order. The provider's timestamp is stored
 * as `provider_status_at`, and an event older than it changes no status. Rows
 * are locked for the update so two events for one record cannot interleave.
 *
 * ── LOCK BY PRIMARY KEY, NEVER ON A MISS ─────────────────────────────────────
 *
 * Rows are FOUND without a lock and then locked by id. `SELECT … FOR UPDATE` on
 * a unique index for a value that is not there takes a GAP lock under InnoDB's
 * repeatable read; the order backfill then writes that very column into the
 * gap. Two first events for two different orders on two workers would hold each
 * other's gap and deadlock. The transaction also retries a deadlock (3 attempts)
 * rather than marking the event failed.
 *
 * Ignored for now (recorded, acted on in later stages): `encounter.assigned`,
 * `encounter.scheduled`, `lab.*`, `prescription.*`, `approval.*`,
 * `subscription.*`, `appointment.*`, `patient.created`, `webhook.test`.
 */
class PrescribeRxWebhookHandler implements InboundWebhookHandler
{
    private const ENCOUNTER_EVENTS = [
        'encounter.created',
        'encounter.status_changed',
        'encounter.prescribed',
        'encounter.completed',
        'encounter.cancelled',
    ];

    private const ORDER_EVENTS = [
        'order.placed',
        'order.paid',
        'order.cancelled',
        'order.refunded',
        'order.voided',
        'order.status_changed',
    ];

    private const FULFILLMENT_EVENTS = [
        'fulfillment.shipped',
        'fulfillment.delivered',
        'fulfillment.cancelled',
    ];

    public function handle(InboundWebhookEvent $event): InboundWebhookOutcome
    {
        $type = $event->event_type;

        if (! in_array($type, [...self::ENCOUNTER_EVENTS, ...self::ORDER_EVENTS, ...self::FULFILLMENT_EVENTS], true)) {
            return InboundWebhookOutcome::ignored();
        }

        // No id means nothing could ever match it (prescribe-rx sends a null
        // `order_id` on fulfillment events it cannot tie to an order), so it
        // is not worth retrying or replaying.
        if ($event->subject_ref === null) {
            return InboundWebhookOutcome::ignored();
        }

        return DB::transaction(fn () => match (true) {
            in_array($type, self::ENCOUNTER_EVENTS, true) => $this->encounter($event),
            in_array($type, self::ORDER_EVENTS, true) => $this->order($event),
            default => $this->fulfillment($event),
        }, 3);
    }

    private function encounter(InboundWebhookEvent $event): InboundWebhookOutcome
    {
        $encounter = $this->lock(Encounter::query()->where('prescribe_rx_encounter_id', $event->subject_ref)->value('id'), Encounter::class);

        if (! $encounter) {
            return InboundWebhookOutcome::unmatched();
        }

        $data = $event->data();
        $at = $this->occurredAt($event);
        $updates = [];

        // The chart our checkout read from the provider's response is already
        // here; fill it only if missing, and make a disagreement visible.
        $chartId = $this->string($data['patient_chart_id'] ?? null);
        if ($chartId !== null && $encounter->prescribe_rx_patient_id === null) {
            $updates['prescribe_rx_patient_id'] = $chartId;
        } elseif ($chartId !== null && $encounter->prescribe_rx_patient_id !== $chartId) {
            Log::warning('prx-webhook: encounter chart id disagrees with the provider; keeping ours.', [
                'encounter_uuid' => $encounter->uuid,
                'event_uuid' => $event->uuid,
            ]);
        }

        // `created` describes the STARTING state, and their `timestamp` is when
        // the listener ran rather than when the change happened — so it may land
        // after a real transition, and our checkout has already recorded the
        // encounter as submitted. It fills an empty provider status and never
        // moves ours.
        $providerStatus = $this->string(match ($event->event_type) {
            'encounter.created' => $encounter->provider_status === null ? ($data['status'] ?? null) : null,
            'encounter.status_changed' => $data['new_status'] ?? null,
            'encounter.prescribed' => 'prescribed',
            'encounter.completed' => 'completed',
            'encounter.cancelled' => 'cancelled',
        });

        // For `created`, the moment that matters is their `created_at`, not the
        // envelope time, or an early `created` could make a later transition
        // look stale.
        if ($event->event_type === 'encounter.created') {
            $at = $this->time($data['created_at'] ?? null)?->startOfSecond() ?? $at;
        }

        if ($providerStatus !== null && ! $this->isStale($encounter->provider_status_at, $at)) {
            $updates['provider_status'] = $providerStatus;
            $updates['provider_status_at'] = $at;

            $coarse = $event->event_type === 'encounter.created' ? null : PrescribeRxStatusMap::encounter($providerStatus);

            if ($coarse !== null) {
                $updates['status'] = $coarse;

                [$column, $source] = match ($coarse) {
                    EncounterStatus::Submitted => ['submitted_at', null],
                    EncounterStatus::Approved => ['reviewed_at', 'prescribed_at'],
                    EncounterStatus::Denied => ['reviewed_at', null],
                    EncounterStatus::Completed => ['completed_at', 'completed_at'],
                    EncounterStatus::Cancelled => ['cancelled_at', 'cancelled_at'],
                    default => [null, null],
                };

                if ($column !== null && $encounter->{$column} === null) {
                    $updates[$column] = $this->time($source ? ($data[$source] ?? null) : null) ?? $at;
                }
            }
        }

        if ($updates !== []) {
            $encounter->update($updates);
        }

        return InboundWebhookOutcome::processed($encounter);
    }

    private function order(InboundWebhookEvent $event): InboundWebhookOutcome
    {
        $data = $event->data();
        $order = $this->findOrder($event->subject_ref, $this->string($data['encounter_id'] ?? null));

        if (! $order) {
            return InboundWebhookOutcome::unmatched();
        }

        $at = $this->occurredAt($event);
        $updates = [];

        if ($order->prescribe_rx_order_id === null) {
            $updates['prescribe_rx_order_id'] = $event->subject_ref;
        }

        $number = $this->string($data['order_number'] ?? null);
        if ($number !== null && $order->prescribe_rx_order_number === null) {
            $updates['prescribe_rx_order_number'] = $number;
        }

        if (! $this->isStale($order->provider_status_at, $at)) {
            $workflow = array_key_exists('workflow_status', $data)
                ? PrescribeRxStatusMap::workflowName($data['workflow_status'])
                : $order->provider_workflow_status;
            $payment = array_key_exists('payment_status', $data)
                ? PrescribeRxStatusMap::paymentName($data['payment_status'])
                : $order->provider_payment_status;
            $shipping = array_key_exists('shipping_status', $data)
                ? PrescribeRxStatusMap::shippingName($data['shipping_status'])
                : $order->provider_shipping_status;

            $updates['provider_workflow_status'] = $workflow;
            $updates['provider_payment_status'] = $payment;
            $updates['provider_shipping_status'] = $shipping;
            $updates['provider_status_at'] = $at;

            $coarse = match (true) {
                $event->event_type === 'order.cancelled' => OrderStatus::Cancelled,
                $event->event_type === 'order.refunded' && ($data['is_partial'] ?? false) !== true => OrderStatus::Refunded,
                default => PrescribeRxStatusMap::order($workflow, $payment, $shipping),
            };

            if ($coarse !== null) {
                $updates['status'] = $coarse;

                [$column, $source] = match ($coarse) {
                    OrderStatus::Cancelled => ['cancelled_at', 'cancelled_at'],
                    OrderStatus::Refunded => ['refunded_at', 'refunded_at'],
                    OrderStatus::Shipped, OrderStatus::PartiallyShipped => ['shipped_at', null],
                    OrderStatus::Delivered => ['delivered_at', null],
                    default => [null, null],
                };

                if ($column !== null && $order->{$column} === null) {
                    $updates[$column] = $this->time($source ? ($data[$source] ?? null) : null) ?? $at;
                }
            }
        }

        $order->update($updates);

        return InboundWebhookOutcome::processed($order);
    }

    /**
     * Shipment-level only. Whether the ORDER as a whole has shipped or been
     * delivered is the provider's `shipping_status` on `order.status_changed`,
     * because one delivered parcel of a split shipment is not a delivered order.
     */
    private function fulfillment(InboundWebhookEvent $event): InboundWebhookOutcome
    {
        $order = $this->lock(Order::query()->where('prescribe_rx_order_id', $event->subject_ref)->value('id'), Order::class);

        if (! $order) {
            return InboundWebhookOutcome::unmatched();
        }

        $data = $event->data();
        $at = $this->occurredAt($event);
        $tracking = $this->string($data['tracking_number'] ?? null, 128);

        if ($event->event_type === 'fulfillment.shipped' && $order->shipped_at === null) {
            $order->update(['shipped_at' => $this->time($data['shipped_at'] ?? null) ?? $at]);
        }

        if ($tracking === null) {
            return InboundWebhookOutcome::processed($order);
        }

        $query = OrderShipment::query()->where('order_id', $order->id)->where('tracking_number', $tracking);

        if ($event->event_type === 'fulfillment.cancelled') {
            $shipment = $query->first();
            $shipment?->update(['status' => ShipmentStatus::Cancelled]);

            return InboundWebhookOutcome::processed($shipment ?? $order);
        }

        $shipment = $query->first() ?? new OrderShipment(['order_id' => $order->id, 'tracking_number' => $tracking]);

        if ($event->event_type === 'fulfillment.shipped') {
            if ($shipment->status !== ShipmentStatus::Delivered) {
                $shipment->status = ShipmentStatus::Shipped;
            }
            $shipment->carrier = $this->string($data['carrier'] ?? null, 32) ?? $shipment->carrier;
            $shipment->tracking_url = $this->string($data['tracking_url'] ?? null, 2048) ?? $shipment->tracking_url;
            $shipment->shipped_at ??= $this->time($data['shipped_at'] ?? null) ?? $at;
        } else {
            $shipment->status = ShipmentStatus::Delivered;
            $shipment->delivered_at ??= $this->time($data['delivered_at'] ?? null) ?? $at;
        }

        $shipment->save();

        return InboundWebhookOutcome::processed($shipment);
    }

    /**
     * By the provider's order id; failing that, the order our checkout created
     * under that encounter before the provider had assigned an order id, which
     * then gets the id so every later event takes the direct path.
     */
    private function findOrder(string $providerOrderId, ?string $providerEncounterId): ?Order
    {
        if ($id = Order::query()->where('prescribe_rx_order_id', $providerOrderId)->value('id')) {
            return $this->lock($id, Order::class);
        }

        if ($providerEncounterId === null) {
            return null;
        }

        $encounterId = Encounter::query()->where('prescribe_rx_encounter_id', $providerEncounterId)->value('id');
        $candidate = $encounterId
            ? Order::query()->where('encounter_id', $encounterId)->whereNull('prescribe_rx_order_id')->latest('id')->value('id')
            : null;
        $order = $this->lock($candidate, Order::class);

        // Another worker backfilled a DIFFERENT provider order onto it while we
        // waited for the lock: not ours to take.
        if ($order && $order->prescribe_rx_order_id !== null && $order->prescribe_rx_order_id !== $providerOrderId) {
            return null;
        }

        return $order;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    private function lock(int|string|null $id, string $model): mixed
    {
        return $id === null ? null : $model::query()->whereKey($id)->lockForUpdate()->first();
    }

    private function isStale(?\DateTimeInterface $current, CarbonImmutable $at): bool
    {
        return $current !== null && $at->lt($current);
    }

    private function occurredAt(InboundWebhookEvent $event): CarbonImmutable
    {
        return CarbonImmutable::parse($event->occurred_at ?? $event->created_at)->startOfSecond();
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function string(mixed $value, int $max = 64): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
