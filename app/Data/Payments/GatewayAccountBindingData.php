<?php

namespace App\Data\Payments;

use App\Enums\Payments\GatewayEnvironment;
use Spatie\LaravelData\Data;

class GatewayAccountBindingData extends Data
{
    public function __construct(
        public int $merchant_account_id,
        public GatewayEnvironment $environment,
        public string $expected_gateway_account_id,
        public string $expected_currency,
        public ?string $expected_provider_mapping = null,
    ) {}
}
