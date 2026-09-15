<?php

namespace App\Data\Payments;

final readonly class GatewayNotificationResult
{
    public function __construct(public int $inbox_id, public string $status, public ?int $conflict_id = null) {}
}
