<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

class PaymentUncertaintyResolutionResult extends Data
{
    public function __construct(public string $operation_uuid, public string $status, public ?int $resolution_id = null,
        public ?int $financial_request_id = null, public ?int $financial_observation_id = null, public ?string $classification = null,
        public bool $currently_qualified = false, public bool $execution_released = false) {}
}
