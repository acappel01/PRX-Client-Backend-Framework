<?php

namespace App\Models\Payments;

class PaymentAccountingLine extends ImmutableFinancialRecord
{
    protected function casts(): array
    {
        return ['debit_minor' => 'integer', 'credit_minor' => 'integer'];
    }
}
