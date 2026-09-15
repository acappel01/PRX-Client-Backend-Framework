<?php

namespace App\Contracts\Payments;

use App\Data\Payments\AuthorizeNetOpaqueAuthorization;
use App\Data\Payments\PaymentDispatchRequest;

/** Trusted application authorization boundary, never a public token/profile DTO adapter. No default binding. */
interface AuthorizeNetInstrumentAuthorization
{
    /** Establish current customer consent/quote/account ownership before returning a single-use ephemeral token. No persistence of instruments. */
    public function authorize(PaymentDispatchRequest $request, string $customerUuid): AuthorizeNetOpaqueAuthorization;
}
