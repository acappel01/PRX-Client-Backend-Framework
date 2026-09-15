<?php

namespace App\Models\Payments;

/** An internal frozen verification configuration; no endpoint or activation is provided. */
class AuthorizeNetReceiver extends ImmutableNotificationRecord
{
    protected $visible = ['id', 'uuid', 'key_version', 'signature_contract', 'configured_at'];

    protected function casts(): array
    {
        return ['webhook_id' => 'encrypted', 'signature_key' => 'encrypted', 'configured_at' => 'immutable_datetime'];
    }
}
