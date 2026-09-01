<?php

namespace App\Data\PrescribeRx;

use Spatie\LaravelData\Data;

/**
 * One entry in the unified intake `products[]` array.
 *
 * Their contract wants EXACTLY ONE identifier per line — `product_id` (UUID),
 * `product_number` (PROD-XXXXX), `product_type_id` or `product_type_slug`.
 * The type variants nominate a placeholder and let the provider pick the final
 * SKU at prescribe-time; we map concrete items, so we send an id or a number.
 *
 * Nulls are stripped before transport (see UnifiedIntakeRequestData) so a line
 * never carries two identifiers and trips their exactly-one rule.
 */
class IntakeProductSelectionData extends Data
{
    public function __construct(
        public ?string $product_id = null,
        public ?string $product_number = null,
        public ?string $product_type_id = null,
        public ?string $product_type_slug = null,
        public ?int $quantity = null,
        public ?float $snapshot_price = null,
    ) {}
}
