<?php

namespace App\Data\Payments;

/** Explicit authenticated acceptance of an existing frozen commercial order, not anonymous cart ownership. */
final readonly class CheckoutTokenGrantData
{
    public function __construct(public string $uuid, public string $preparation_uuid,
        public string $accepted_quote_fingerprint, public int $accepted_amount_minor,
        public string $accepted_currency, public string $acknowledgement_version) {}
}
