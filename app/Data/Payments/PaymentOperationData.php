<?php

namespace App\Data\Payments;

use App\Enums\Payments\PaymentOperationPurpose;
use Spatie\LaravelData\Data;

class PaymentOperationData extends Data
{
    public function __construct(
        public string $uuid,
        public string $intent_uuid,
        public PaymentOperationPurpose $purpose,
        public int $amount_minor,
        public string $executor_key,
        public ?string $original_operation_uuid = null,
    ) {}
}
