<?php

namespace App\Data\PrescribeRx;

use Spatie\LaravelData\Data;

/**
 * One entry in the unified intake `packages[]` array.
 *
 * This is the field that makes a bundle land on their side as a BUNDLE.
 * prescribe-rx already knows which products a package contains, plus the labs,
 * shipping and telehealth-consult behaviour keyed off it — so naming the
 * package delegates all of that back to the side that owns it. Flattening a
 * package into member product ids (the legacy `product_ids` shape) throws that
 * away and none of their bundle machinery fires.
 *
 * `plan_id` selects the subscription term for the package.
 */
class IntakePackageSelectionData extends Data
{
    public function __construct(
        public ?string $package_id = null,
        public ?string $package_number = null,
        public ?string $plan_id = null,
    ) {}
}
