<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

class GatewayTransactionReadData extends Data
{
    public function __construct(
        public int $binding_id,
        public string $transaction_id,
        public string $expected_transaction_type,
        public ?string $expected_original_transaction_id,
        public int $expected_amount_minor,
        public string $expected_currency,
    ) {}
}
