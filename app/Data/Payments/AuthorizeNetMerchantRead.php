<?php

namespace App\Data\Payments;

use Spatie\LaravelData\Data;

class AuthorizeNetMerchantRead extends Data
{
    public function __construct(public string $gateway_account_id, public string $currency, public array $processors = []) {}
}
