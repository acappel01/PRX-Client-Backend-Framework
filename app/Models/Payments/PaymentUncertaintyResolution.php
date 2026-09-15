<?php

namespace App\Models\Payments;

/** Immutable evidence-backed resolution audit; never a dispatch release or permanent eligibility flag. */
class PaymentUncertaintyResolution extends ImmutableFinancialRecord
{
    protected $hidden = ['uncertainty_fingerprint', 'evidence_fingerprint', 'evidence'];

    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array', 'resolved_at' => 'immutable_datetime'];
    }
}
