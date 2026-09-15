<?php

namespace App\Models\Payments;

class PaymentFinancialObservation extends ImmutableFinancialRecord
{
    protected $hidden = ['facts'];

    protected function casts(): array
    {
        return ['facts' => 'encrypted:array', 'recorded_at' => 'immutable_datetime'];
    }
}
