<?php

namespace App\Data\Payments;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/** Current gateway facts only: never a verified local operation or paid projection. */
class AuthorizeNetTransactionRead extends Data
{
    public function __construct(
        public string $canonical_account_key,
        public string $transaction_id,
        public string $transaction_type,
        public string $transaction_status,
        public ?string $original_transaction_id,
        public int $authorized_amount_minor,
        public int $settlement_amount_minor,
        public string $currency,
        public CarbonImmutable $read_at,
        public string $currency_authority = 'current_merchant_configuration',
        public bool $transaction_currency_verified = false,
    ) {}
}
