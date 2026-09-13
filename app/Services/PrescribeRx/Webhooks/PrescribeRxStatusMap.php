<?php

namespace App\Services\PrescribeRx\Webhooks;

use App\Enums\EncounterStatus;
use App\Enums\OrderStatus;

/**
 * prescribe-rx's status vocabulary → this install's coarse statuses.
 *
 * Source of truth is their enums: `App\Enums\Encounter\EncounterStatus`
 * (string-backed, 29 values) and, on an order, `Shared\WorkflowStatus`,
 * `Shared\PaymentStatus` and `Logistics\ShippingStatus` — which are
 * INTEGER-backed, so a webhook carries `"workflow_status": 3`. The integers are
 * translated to their names here so the admin shows a word, not a code.
 *
 * Anything not listed maps to null, and null means "leave our status alone":
 * guessing a coarse state for a value we have never seen is how the previous
 * receiver turned every encounter back into Pending.
 */
final class PrescribeRxStatusMap
{
    private const ENCOUNTER = [
        'cart' => EncounterStatus::Pending,
        'pending_intake' => EncounterStatus::Pending,

        'unassigned' => EncounterStatus::Submitted,
        'intake_submitted' => EncounterStatus::Submitted,
        'awaiting_provider' => EncounterStatus::Submitted,
        'awaiting_scheduling' => EncounterStatus::Submitted,
        'scheduled' => EncounterStatus::Submitted,
        'rescheduled' => EncounterStatus::Submitted,
        'awaiting_labs' => EncounterStatus::Submitted,
        'labs_ordered' => EncounterStatus::Submitted,
        'labs_received' => EncounterStatus::Submitted,

        'pending_provider_review' => EncounterStatus::InReview,
        'provider_in_progress' => EncounterStatus::InReview,
        'intake_review' => EncounterStatus::InReview,
        'labs_in_review' => EncounterStatus::InReview,
        'labs_reviewed' => EncounterStatus::InReview,
        'in_progress' => EncounterStatus::InReview,
        'provider_review' => EncounterStatus::InReview,
        'requires_information' => EncounterStatus::InReview,
        'awaiting_prescription' => EncounterStatus::InReview,
        'awaiting_patient_review' => EncounterStatus::InReview,
        'on_hold' => EncounterStatus::InReview,

        'prescribed' => EncounterStatus::Approved,
        'rejected' => EncounterStatus::Denied,
        'completed' => EncounterStatus::Completed,
        'complete_no_rx' => EncounterStatus::Completed,
        'cancelled' => EncounterStatus::Cancelled,
        'no_show' => EncounterStatus::Cancelled,
        'referred_out' => EncounterStatus::Cancelled,
    ];

    private const WORKFLOW = [
        0 => 'processing', 1 => 'pending_provider_review', 2 => 'provider_review',
        3 => 'ready_for_fulfillment', 4 => 'fulfillment_in_progress', 5 => 'completed',
        6 => 'canceled', 7 => 'med_ineligible', 8 => 'med_denied',
        9 => 'pending_payment_review', 10 => 'revision_requested',
    ];

    private const PAYMENT = [
        0 => 'pending', 1 => 'authorized', 2 => 'captured', 3 => 'partially_captured',
        4 => 'voided', 5 => 'refunded', 6 => 'failed', 7 => 'settled',
        8 => 'partially_refunded', 9 => 'chargeback', 10 => 'held_for_review',
        11 => 'client_billed', 12 => 'invoiced',
    ];

    private const SHIPPING = [
        0 => 'none', 1 => 'label_created', 2 => 'shipped', 3 => 'in_transit',
        4 => 'delivered', 5 => 'exception', 6 => 'returned', 7 => 'canceled',
        8 => 'partially_shipped',
    ];

    public static function encounter(?string $providerStatus): ?EncounterStatus
    {
        return $providerStatus === null ? null : (self::ENCOUNTER[$providerStatus] ?? null);
    }

    public static function workflowName(mixed $value): ?string
    {
        return self::name(self::WORKFLOW, $value);
    }

    public static function paymentName(mixed $value): ?string
    {
        return self::name(self::PAYMENT, $value);
    }

    public static function shippingName(mixed $value): ?string
    {
        return self::name(self::SHIPPING, $value);
    }

    /**
     * The coarse order status implied by the provider's three, most decisive
     * first. Null when none of them says anything we map (e.g. a freshly placed
     * order in `processing`, which our own `pending` already describes).
     */
    public static function order(?string $workflow, ?string $payment, ?string $shipping): ?OrderStatus
    {
        return match (true) {
            $payment === 'refunded' => OrderStatus::Refunded,
            in_array($workflow, ['canceled', 'med_ineligible', 'med_denied'], true) => OrderStatus::Cancelled,
            $shipping === 'delivered' => OrderStatus::Delivered,
            $shipping === 'partially_shipped' => OrderStatus::PartiallyShipped,
            in_array($shipping, ['shipped', 'in_transit'], true) => OrderStatus::Shipped,
            in_array($workflow, [
                'pending_provider_review', 'provider_review', 'ready_for_fulfillment',
                'fulfillment_in_progress', 'pending_payment_review', 'revision_requested', 'completed',
            ], true) => OrderStatus::Processing,
            default => null,
        };
    }

    /**
     * Integer codes become names; a string that is already one of the names is
     * kept (their payloads fall back to the raw value when a cast is missing);
     * an unknown value is kept verbatim so it is visible rather than lost.
     *
     * @param  array<int, string>  $names
     */
    private static function name(array $names, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return $names[(int) $value] ?? (string) $value;
        }

        return is_string($value) ? mb_substr($value, 0, 64) : null;
    }
}
