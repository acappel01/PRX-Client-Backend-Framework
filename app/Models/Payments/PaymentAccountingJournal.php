<?php

namespace App\Models\Payments;

/** Immutable operational control posting; never a bank-cash or revenue-recognition record. */
class PaymentAccountingJournal extends ImmutableFinancialRecord
{
    protected $hidden = ['facts', 'entity_key', 'economic_fingerprint'];

    protected function casts(): array
    {
        return ['facts' => 'encrypted:array', 'posted_at' => 'immutable_datetime', 'amount_minor' => 'integer'];
    }
}
