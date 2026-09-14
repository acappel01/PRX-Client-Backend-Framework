<?php

namespace App\Models\Attribution;

/** Passive audit; even an otherwise eligible preview is blocked pending suppression evidence. */
class CanonicalDeliveryEvaluation extends AppendOnlyRecord
{
    protected $hidden = ['canonical_delivery_id', 'projection', 'policy_evidence'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'projection' => 'encrypted:array', 'policy_evidence' => 'encrypted:array', 'evaluated_at' => 'immutable_datetime'];
    }
}
