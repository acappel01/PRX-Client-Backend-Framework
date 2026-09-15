<?php

namespace App\Services\Payments;

class PaymentReferenceGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(10));
    }
}
