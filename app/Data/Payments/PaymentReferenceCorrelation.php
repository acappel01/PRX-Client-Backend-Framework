<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

/** Reference comparison only; never a verified monetary effect or dispatch receipt. */
class PaymentReferenceCorrelation extends Data
{
    public function __construct(public string $operation_uuid, public AuthorizeNetTransactionRead $read,
        public string $status = 'reference_matched_only', public bool $operation_verified = false) {}
}
