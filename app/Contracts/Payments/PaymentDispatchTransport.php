<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentDispatchReceipt;
use App\Data\Payments\PaymentDispatchRequest;

/** No production implementation or default container binding is shipped. */
interface PaymentDispatchTransport
{
    public function key(): string;

    /** Pure local capability declaration: no network or side effects. */
    public function supports(string $purpose): bool;

    /**
     * A future real adapter MUST resolve/validate frozen merchant_binding_fingerprint
     * before remote IO, use that exact credential snapshot, and preserve the durable
     * reference and original target. It must not resolve new credentials silently,
     * retry mutations internally, or treat this invocation as provider acceptance.
     * Instrument/quote authorization and real adapter qualification remain separate.
     */
    public function dispatch(PaymentDispatchRequest $request): PaymentDispatchReceipt;
}
