<?php

namespace App\Data\Payments;

use App\Enums\Payments\GatewayEnvironment;
use Spatie\LaravelData\Data;

class AuthorizeNetReceiverData extends Data
{
    public function __construct(public int $binding_id, public GatewayEnvironment $environment, public string $webhook_id, public string $key_version) {}
}
