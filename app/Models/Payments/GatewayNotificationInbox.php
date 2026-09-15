<?php

namespace App\Models\Payments;

/** Authenticated inactive notification, never a financial outcome. */
class GatewayNotificationInbox extends ImmutableNotificationRecord
{
    protected $visible = ['id', 'state', 'received_at'];

    protected function casts(): array
    {
        return ['notification' => 'encrypted:array', 'received_at' => 'immutable_datetime'];
    }
}
