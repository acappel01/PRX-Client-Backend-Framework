<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

class PaymentAccountingResult extends Data
{
    public function __construct(public string $order_uuid, public string $status, public array $journal_ids = [],
        public ?array $amounts = null, public bool $execution_released = false, public bool $bank_cash_verified = false,
        public ?string $currency = null, public ?string $environment = null, public ?string $canonical_account_key = null) {}
}
