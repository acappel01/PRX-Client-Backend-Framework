<?php

namespace App\Data\PrescribeRx;

use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Single-call payload for `POST /telehealth/intake/unified` —
 * creates patient + encounter + intake answers atomically on the
 * prescribe-rx side.
 *
 * One of `encounter_type_id` / `encounter_type_slug` /
 * `encounter_type_name` MUST be set (priority: id > slug > name).
 */
class UnifiedIntakeRequestData extends Data
{
    public function __construct(
        #[Required]
        public PatientData $patient,
        public ?string $encounter_type_id = null,
        public ?string $encounter_type_slug = null,
        public ?string $encounter_type_name = null,
        public ?string $client_id = null,
        public ?string $client_number = null,
        public ?string $sales_org_id = null,
        public ?string $sales_org_number = null,
        public ?VitalsData $vitals = null,
        public ?MedicalHistoryData $medical_history = null,
        /**
         * Keyed by field slug from
         * `GET /telehealth/encounter-types/{id}/schema`.
         *
         * @var array<string, mixed>
         */
        public array $answers = [],
        /**
         * Modern selection array. Prefer this over `product_ids`.
         *
         * @var DataCollection<int, IntakeProductSelectionData>|null
         */
        public ?DataCollection $products = null,
        /**
         * Modern selection array — the shape that makes a bundle land as a
         * bundle, with its term. Prefer this over `product_ids`.
         *
         * @var DataCollection<int, IntakePackageSelectionData>|null
         */
        public ?DataCollection $packages = null,
        /**
         * LEGACY, documented as deprecated by prescribe-rx. Flattens a package
         * into member product ids, which discards the package identity their
         * labs / shipping / consult behaviour keys off. Kept for backward
         * compatibility only — populate `products` / `packages` instead.
         *
         * @var array<int, string>
         */
        public array $product_ids = [],
        public ?string $reason_for_visit = null,
        /** Free-form, max 1000 chars. Distinct from `reason_for_visit`. */
        public ?string $chief_complaint = null,
        /**
         * Skips provider auto-assignment, fulfilment and billing on their
         * side. Their server also auto-sets it on test-looking names.
         */
        public ?bool $is_sandbox = null,
        /**
         * Opaque pass-through, persisted to `encounters.metadata` and shown
         * in their admin. Our lead uuid and campaign attribution ride here so
         * an encounter can be traced back to the visit that produced it.
         *
         * @var array<string, mixed>|null
         */
        public ?array $metadata = null,
        /** @var DataCollection<int, ConsentData>|null */
        public ?DataCollection $consents = null,
    ) {}
}
