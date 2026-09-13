<?php

namespace App\Services\PrescribeRx\Webhooks;

use App\Data\Webhooks\ParsedInboundWebhook;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Reads a prescribe-rx webhook envelope.
 *
 * Shape, from their sender (`WebhookDispatchService`, `WebhookPayloadBuilder`):
 *
 *   { "event": "encounter.status_changed", "timestamp": "ISO-8601",
 *     "webhook_id": "uuid", "subscription_id": "uuid", "data": { … } }
 *
 * `data` carries typed ids (`encounter_id`, `order_id`, `patient_chart_id`, …)
 * and statuses — never `id`, `metadata` or a lead reference. `webhook_id` is
 * fresh per event × subscription and survives their retries, so it is the
 * dedupe id. It is read from the SIGNED body, never from the `X-Webhook-ID`
 * header, which the signature does not cover.
 *
 * `data` is reduced to ALLOWED_KEYS. Some events carry free text typed by
 * clinic staff — `reason` on a cancellation, `note` on a refill request,
 * `failure_reason`, `provider_name` — and this install does not store it.
 * A field prescribe-rx adds later is dropped until it is added here.
 */
class PrescribeRxWebhookParser
{
    /** Ids, statuses, timestamps, amounts and tracking. Nothing typed by a person. */
    private const ALLOWED_KEYS = [
        // encounters
        'encounter_id', 'encounter_number', 'encounter_type_id', 'patient_chart_id', 'patient_number',
        'status', 'old_status', 'new_status', 'created_at', 'assigned_at', 'prescribed_at',
        'completed_at', 'cancelled_at', 'scheduled_at', 'provider_profile_id',
        // orders
        'order_id', 'order_number', 'external_order_id', 'gateway_transaction_id',
        'total_amount', 'amount', 'refund_amount', 'voided_amount', 'is_partial', 'currency',
        'payment_status', 'workflow_status', 'shipping_status', 'status_field', 'changed_fields',
        'line_count', 'paid_at', 'refunded_at', 'voided_at',
        // fulfillment
        'tracking_number', 'tracking_url', 'carrier', 'shipped_at', 'delivered_at',
        // labs, prescriptions, subscriptions, appointments (recorded, not yet acted on)
        'lab_order_id', 'lab_order_number', 'lab_center_id', 'received_at', 'reviewed_at', 'flagged_at',
        'critical_count', 'prescription_id', 'prescription_number', 'signed_at',
        'subscription_id', 'subscription_number', 'package_id', 'billing_term', 'grand_total',
        'next_bill_at', 'last_billed_at', 'appointment_id', 'duration_minutes',
        'client_id', 'sales_organization_id', 'source', 'event_type', 'timestamp',
    ];

    /** Event prefix → [subject type, the data key holding its prescribe-rx id]. */
    private const SUBJECTS = [
        'encounter' => ['encounter', 'encounter_id'],
        'order' => ['order', 'order_id'],
        'fulfillment' => ['order', 'order_id'],
        'lab' => ['lab_order', 'lab_order_id'],
        'prescription' => ['prescription', 'prescription_id'],
        'subscription' => ['subscription', 'subscription_id'],
        'appointment' => ['appointment', 'appointment_id'],
        'patient' => ['patient_chart', 'patient_chart_id'],
    ];

    /**
     * @param  array<string, mixed>  $envelope
     *
     * @throws InvalidArgumentException when the body is not a prescribe-rx event
     */
    public function parse(array $envelope): ParsedInboundWebhook
    {
        $event = $envelope['event'] ?? null;

        if (! is_string($event) || $event === '' || strlen($event) > 64) {
            throw new InvalidArgumentException('The webhook has no event type.');
        }

        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $prefix = strstr($event, '.', true) ?: $event;
        [$subjectType, $subjectKey] = self::SUBJECTS[$prefix] ?? [null, null];

        return new ParsedInboundWebhook(
            eventType: $event,
            providerEventId: $this->id($envelope['webhook_id'] ?? null),
            subjectType: $subjectType,
            subjectRef: $subjectKey ? $this->id($data[$subjectKey] ?? null) : null,
            occurredAt: $this->time($envelope['timestamp'] ?? null),
            payload: $this->allowlist($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function allowlist(array $data): array
    {
        $kept = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            // Scalars only; `changed_fields` is a list of column names.
            if ($value === null || is_scalar($value)) {
                $kept[$key] = is_string($value) ? mb_substr($value, 0, 2048) : $value;
            } elseif ($key === 'changed_fields' && is_array($value)) {
                $kept[$key] = array_values(array_filter(
                    array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 64) : null, array_is_list($value) ? $value : array_keys($value)),
                ));
            }
        }

        return $kept;
    }

    private function id(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && $value !== '' && strlen($value) <= 64 ? $value : null;
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
}
