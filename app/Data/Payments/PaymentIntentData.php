<?php

namespace App\Data\Payments;

use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use Spatie\LaravelData\Data;

/** A trusted caller's financial obligation, not proof of collection. */
class PaymentIntentData extends Data
{
    public function __construct(
        public string $uuid,
        public int $order_id,
        public int $customer_id,
        public int $merchant_account_id,
        public GatewayProvider $gateway_provider,
        public GatewayEnvironment $environment,
        public int $amount_minor,
        public string $currency,
        public string $executor_key,
    ) {}
}
