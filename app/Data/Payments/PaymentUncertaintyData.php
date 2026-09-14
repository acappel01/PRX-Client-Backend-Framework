<?php

namespace App\Data\Payments;

use App\Enums\Payments\PaymentUncertaintyReason;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/** No raw response, card, bank, nonce, customer profile or arbitrary metadata. */
class PaymentUncertaintyData extends Data
{
    public function __construct(
        public string $operation_uuid,
        public PaymentUncertaintyReason $reason,
        public CarbonImmutable $observed_at,
        public ?string $gateway_transaction_reference = null,
        public ?string $original_gateway_transaction_reference = null,
    ) {}
}
