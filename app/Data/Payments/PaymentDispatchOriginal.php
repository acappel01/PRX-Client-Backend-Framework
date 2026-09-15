<?php

namespace App\Data\Payments;

final readonly class PaymentDispatchOriginal
{
    public function __construct(
        public string $original_transaction_id,
        public int $parent_preparation_id,
        public int $parent_operation_id,
        public string $canonical_account_key,
        public string $environment,
        public string $evidence_fingerprint,
        public array $ancestor_operation_ids = [],
    ) {}
}
