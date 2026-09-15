<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

/** Current reported gateway amounts; never an order-paid, bank-cash or spendable balance claim. */
class OrderFinancialResolution extends Data
{
    public function __construct(
        public string $order_uuid,
        public string $currency,
        public string $status,
        public ?array $amounts = null,
        public array $observation_ids = [],
        public bool $qualified_reported_amounts = false,
        public bool $bank_cash_verified = false,
        public ?string $environment = null,
        public ?string $canonical_account_key = null,
    ) {}
}
