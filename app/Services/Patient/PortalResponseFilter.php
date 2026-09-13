<?php

namespace App\Services\Patient;

/**
 * Allowlist filter for everything the patient-portal proxy returns.
 *
 * WHY THIS IS AN ALLOWLIST, NOT A DENYLIST. Two of the endpoints we proxy
 * (`/me/patient/encounters` and `/me/patient/orders`) return `$paginator->items()`
 * straight into the JSON envelope — raw Eloquent models with no `$hidden`, no
 * `$visible` and no resource class (prx-demo@07969f8:
 * Me/PatientSelfServiceController.php:592-596 and :615-619, via
 * Http/Traits/ApiResponseTrait.php:238-248). The wire payload is therefore the
 * whole table, and the table grows. A denylist would have been correct on the
 * day it was written and wrong at the next PRX migration; the fields it did not
 * yet know to name would ship straight to a browser. An allowlist fails the
 * other way — a new field is invisible until someone adds it here, which is a
 * missing feature rather than a disclosure.
 *
 * What that dump actually carries, verified against the migrations: an
 * encounter row holds `video_room_token` (a live credential), `encounter_data`,
 * and the `patient_intake_snapshot` / `provider_intake_snapshot` /
 * `soap_note_snapshot` / `treatment_plan_snapshot` blobs; an order row holds
 * `profit_margin`, `total_cost`, `gateway_transaction_id`,
 * `authorization_transaction_id` and `idempotency_key`. None of that is the
 * patient's to see, and some of it is not even ours to hold.
 *
 * A spec is a nested array:
 *   'field'            → keep the scalar as-is
 *   'field' => [spec]  → recurse; works for both an object and a list of objects
 *
 * Anything absent from the spec is dropped silently. Anything present in the
 * spec but missing from the payload is simply absent from the output — the
 * filter never invents a key, so a caller can still distinguish "PRX sent null"
 * from "PRX sent nothing".
 */
class PortalResponseFilter
{
    /**
     * @var array<string, array<int|string, mixed>>
     */
    private const SPECS = [
        'dashboard' => [
            'current_weight',
            'weight_goal',
            'progress_percent',
            'active_prescriptions',
            'upcoming_encounters',
            'recent_orders' => [
                'id',
                'order_number',
                'grand_total',
                'workflow_status',
                'created_at',
            ],
        ],

        // Raw model dump. Everything not named here is dropped, including the
        // video credential and all four clinical snapshots.
        'encounters' => [
            'id',
            'encounter_number',
            'status',
            'interaction_type',
            'encounter_type_id',
            'reason_for_visit',
            'chief_complaint',
            'scheduled_at',
            'scheduled_for',
            'started_at',
            'completed_at',
            'cancelled_at',
            'prescribed_at',
            'no_show_at',
            'rescheduled_at',
            'reschedule_count',
            'info_request_message',
            // The patient's own reply. Confirmed present on the live payload
            // 2026-09-07; needed so the "requires information" screen can show
            // what was already sent rather than asking twice.
            'info_response_message',
            'info_requested_at',
            'info_responded_at',
            'created_at',
            'updated_at',
        ],

        // `identity` is dropped: it is a PRX-user-derived string, and the Twilio
        // SDK hands the joined participant their own identity anyway.
        'video-token' => [
            'token',
            'room_name',
            'provider',
            'expires_at',
        ],

        // TRANSCRIBED FROM THE HANDLER, NOT FROM A DTO — and the distinction
        // cost a review cycle. `/me/patient/vitals` serialises through
        // `PatientSelfServiceController::formatVital` (prx-demo@07969f8:1351-1373),
        // which emits `weight` / `height` / `systolic_bp`. The `weight_lbs` /
        // `blood_pressure_systolic` names belong to the REQUEST body of the same
        // endpoint and to `PatientVitalResource` on the org-token route. Naming
        // the request's fields here stripped weight, height and blood pressure
        // out of every reading while the allowlist reported success.
        'vitals' => [
            'id',
            'weight',
            'height',
            'bmi',
            'systolic_bp',
            'diastolic_bp',
            'heart_rate',
            'temperature',
            'respiratory_rate',
            'oxygen_saturation',
            'blood_glucose',
            'glucose_timing',
            'pain_level',
            'pain_location',
            'source',
            'notes',
            'is_abnormal',
            'created_at',
            // Present only on the POST response, and only when a previous
            // reading exists to compare against.
            'change_from_last' => ['weight', 'period_days'],
        ],

        // Raw model dump. Margin, wholesale cost, gateway ids, the billing and
        // commission ledger, and the federation snapshots are all dropped.
        'orders' => [
            'id',
            'order_number',
            'created_at',
            'currency',
            'items_quantity',
            'product_total',
            'total_shipping',
            'discount_total',
            'grand_total',
            'coupon_code',
            'payment_status',
            'workflow_status',
            'shipping_status',
            'fully_shipped_at',
            'tracking_number',
            'tracking_url',
            'shipping_carrier_code',
            'customer_notes',
            'is_package_order',
            'ship_to',
            // `sub_total` is on the live payload and is NOT added: we cannot say
            // from source whether it is before or after shipping and discount,
            // and a money figure that is wrong on a patient's screen is worse
            // than one that is absent. Add it when the definition is confirmed.
        ],

        // Everything the patient came for is NESTED UNDER `items` — the dose is
        // `items[].sig`, not a top-level field (prx-demo@07969f8:645-672). A flat
        // spec here collapsed each prescription to number and status, which is
        // the "lead with the answer" rule failing at the data layer rather than
        // in the markup.
        //
        // `clinical_notes` is deliberately absent: its intended audience is
        // undetermined, and `sig` + `patient_instructions` + `titration` answer
        // the patient's question. Add it only once PRX confirms it is
        // patient-facing.
        'prescriptions' => [
            'id',
            'prescription_number',
            'status',
            'prescribed_at',
            'expires_at',
            'refills',
            'prescriber',
            'items' => [
                'product_name',
                'sig',
                'quantity',
                'days_supply',
                'refills',
                'patient_instructions',
                'titration' => [
                    'step_order',
                    'label',
                    'start_day',
                    'end_day',
                    'dose_amount',
                    'dose_unit',
                    'frequency',
                    'instructions',
                ],
            ],
        ],

        // `sender_id` is a PRX-internal user uuid and is dropped everywhere.
        // Whether a message is the patient's own is answered by `is_mine`.
        'conversations' => [
            'id',
            'type',
            'subject',
            'encounter_id',
            'encounter_number',
            'unread_count',
            'updated_at',
            'participants' => ['participant_type', 'name'],
            'last_message' => ['content', 'created_at'],
        ],

        'messages' => [
            'id',
            'content',
            'sender_name',
            'is_mine',
            'message_type',
            'reply_to_message_id',
            'created_at',
        ],

        // A poll with `after`: the new messages, oldest first, and the cursor
        // to use next. `latest_cursor` is null when nothing arrived.
        'messages-poll' => [
            'count',
            'latest_cursor',
            'has_more',
            'messages' => [
                'id',
                'content',
                'sender_name',
                'is_mine',
                'message_type',
                'reply_to_message_id',
                'created_at',
            ],
        ],

        'message' => [
            'id',
            'content',
            'message_type',
            'reply_to_message_id',
            'created_at',
        ],

        // What a held visit still needs. Labels are replaced by the operator's
        // wording (PortalSettings::requirement_labels) in the controller.
        'requirements' => [
            'encounter_id',
            'status',
            'resolvable_via_api',
            'info_request_message',
            'completeness_pct',
            'missing',
            'items' => ['slug', 'label', 'type', 'satisfied'],
        ],

        'provide-information' => [
            'released',
            'missing',
            'missing_fields',
            'missing_docs',
        ],

        'conversation-opened' => [
            'conversation_id',
            'encounter_id',
            'subject',
        ],

        'slots' => [
            'date_range' => ['start', 'end'],
            'lab_offset_days',
            'has_valid_labs',
            'total_slots',
            'slots' => [
                'date',
                'display',
                'slot_count',
                'slots' => [
                    'start',
                    'end',
                    'display_start',
                    'display_end',
                    'display_time',
                    'provider_profile_id',
                    'timezone',
                ],
            ],
        ],

        // The tenancy ids PRX echoes back (`client_id`, `sales_organization_id`,
        // `patient_chart_id`, `provider_profile_id`) are dropped. The first three
        // the browser never supplied and has no use for. `provider_profile_id`
        // it DID supply — it is the booking handle from the slots response — and
        // echoing it back tells the patient nothing they can act on.
        'appointment' => [
            'id',
            'appointment_number',
            'encounter_id',
            'status',
            'status_label',
            'scheduled_start',
            'scheduled_end',
            'duration_minutes',
            'interaction_type',
            'interaction_type_label',
            'patient_timezone',
            'scheduled_start_patient_tz',
            'notes',
            'confirmed_at',
            'time_until',
            'created_at',
        ],
    ];

    /**
     * Filter a PRX payload for one portal screen.
     *
     * Accepts either a single associative payload or a list of them, because
     * PRX returns both shapes and the caller should not have to care which.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function apply(string $screen, array $payload): array
    {
        if (! isset(self::SPECS[$screen])) {
            // Fail CLOSED. An unregistered screen returning the payload
            // untouched would make forgetting a spec the silent default, which
            // is precisely the failure this class exists to prevent.
            throw new \InvalidArgumentException(
                "No portal response spec for screen [{$screen}]. Add one before proxying it."
            );
        }

        return $this->filter($payload, self::SPECS[$screen]);
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<int|string, mixed>  $spec
     * @return array<mixed>
     */
    private function filter(array $value, array $spec): array
    {
        if (array_is_list($value)) {
            return array_values(array_map(
                fn ($item) => is_array($item) ? $this->filter($item, $spec) : $item,
                $value
            ));
        }

        $out = [];

        foreach ($spec as $key => $nested) {
            if (is_int($key)) {
                // Scalar leaf.
                if (array_key_exists($nested, $value)) {
                    $out[$nested] = $value[$nested];
                }

                continue;
            }

            if (! array_key_exists($key, $value)) {
                continue;
            }

            // A nested key whose value is null (or anything non-array) passes
            // through as-is: `last_message` is legitimately null on an empty
            // conversation, and coercing that to [] would read as "a message
            // with no fields".
            $out[$key] = is_array($value[$key])
                ? $this->filter($value[$key], $nested)
                : $value[$key];
        }

        return $out;
    }
}
