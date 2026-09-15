<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentDispatchOriginal;
use App\Models\Payments\PaymentDispatchPreparation;

interface PaymentDispatchLineageResolver
{
    /** Trusted local current-read proof, invoked inside the claim transaction; no HTTP. */
    public function resolve(PaymentDispatchPreparation $preparation): PaymentDispatchOriginal;
}
