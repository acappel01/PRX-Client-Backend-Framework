<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

/** A point-in-time evidence assessment, never authority to execute or project money. */
class PaymentAssociationResolution extends Data
{
    public function __construct(
        public int $preparation_id,
        public string $status,
        public array $association_ids,
        public bool $dispatch_verified = false,
        public bool $operation_verified = false,
        public bool $financial_effects_verified = false,
    ) {}
}
