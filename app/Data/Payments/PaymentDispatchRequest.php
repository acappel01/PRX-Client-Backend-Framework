<?php

namespace App\Data\Payments;

/** Exact frozen non-instrument request; not a public payment payload. */
final readonly class PaymentDispatchRequest
{
    public function __construct(
        public string $attempt_uuid,
        public string $preparation_uuid,
        public string $operation_uuid,
        public int $gateway_account_binding_id,
        public string $canonical_account_key,
        public string $environment,
        public string $merchant_reference,
        public string $purpose,
        public int $amount_minor,
        public string $currency,
        public ?string $original_transaction_id,
        public string $request_fingerprint,
        public string $merchant_binding_fingerprint,
    ) {}
}
