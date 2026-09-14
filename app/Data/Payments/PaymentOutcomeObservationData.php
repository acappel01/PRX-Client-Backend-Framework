<?php

namespace App\Data\Payments;

use App\Enums\Payments\GatewayEnvironment;
use App\Enums\Payments\GatewayProvider;
use App\Enums\Payments\ReportedPaymentOutcome;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/** Trusted producer input, not authentication or permission to project money. */
class PaymentOutcomeObservationData extends Data
{
    public function __construct(
        public string $uuid,
        public string $operation_uuid,
        public string $merchant_account_uuid,
        public GatewayProvider $gateway_provider,
        public GatewayEnvironment $environment,
        public string $source_key,
        public ReportedPaymentOutcome $reported_outcome,
        public CarbonImmutable $observed_at,
        public ?string $gateway_transaction_reference = null,
        public ?string $original_gateway_transaction_reference = null,
        public ?string $source_event_reference = null,
        public ?int $reported_amount_minor = null,
        public ?string $reported_currency = null,
    ) {}
}
