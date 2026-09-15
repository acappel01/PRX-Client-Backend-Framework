<?php

namespace App\Data\Payments;

/** Minimal trusted transport return. Reported response code is not verified payment acceptance. */
final readonly class PaymentDispatchReceipt
{
    public function __construct(
        public string $request_fingerprint,
        public string $response_code,
        public ?string $transaction_id,
        public ?string $echoed_ref_id,
        public ?string $response_original_id = null,
    ) {}
}
