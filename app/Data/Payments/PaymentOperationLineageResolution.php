<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

/** Actor correlation to a dispatch-owned response is not settlement or available money. */
class PaymentOperationLineageResolution extends Data
{
    public function __construct(
        public int $preparation_id,
        public string $status,
        public array $effect_association_ids,
        public bool $actor_correlated = false,
        public bool $operation_verified = false,
        public bool $financial_effects_verified = false,
    ) {}
}
