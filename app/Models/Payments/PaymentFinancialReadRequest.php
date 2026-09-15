<?php

namespace App\Models\Payments;

class PaymentFinancialReadRequest extends ImmutableFinancialRecord
{
    protected $hidden = ['entity_key', 'scope_fingerprint'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime'];
    }
}
