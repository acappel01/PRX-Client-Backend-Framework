<?php

namespace App\Models\Payments;

class GatewayNotificationConflict extends ImmutableNotificationRecord
{
    protected $visible = ['id', 'recorded_at'];

    protected function casts(): array
    {
        return ['notification' => 'encrypted:array', 'recorded_at' => 'immutable_datetime'];
    }
}
