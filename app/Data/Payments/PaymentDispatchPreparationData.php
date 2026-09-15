<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

class PaymentDispatchPreparationData extends Data
{
    public function __construct(
        public string $uuid,
        public int $payment_operation_reference_id,
        public string $executor_key,
    ) {}
}
