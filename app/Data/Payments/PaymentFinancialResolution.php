<?php

namespace App\Data\Payments;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/** Qualified provider-reported classification, never bank cash or an order-paid projection. */
class PaymentFinancialResolution extends Data
{
    public function __construct(public int $preparation_id, public string $canonical_account_key, public string $environment,
        public string $currency, public string $status, public ?string $entity_key = null, public ?int $request_id = null,
        public ?int $observation_id = null, public ?string $classification = null, public array $amounts = [],
        public bool $qualified_reported_amounts = false, public ?CarbonImmutable $reported_at = null,
        public ?CarbonImmutable $request_started_at = null, public bool $financial_effects_verified = false) {}
}
