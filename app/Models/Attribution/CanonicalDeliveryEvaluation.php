<?php

namespace App\Models\Attribution;

/** Passive audit; an otherwise policy-eligible preview still has delivery disabled. */
class CanonicalDeliveryEvaluation extends AppendOnlyRecord
{
    protected $hidden = ['canonical_delivery_id', 'projection', 'policy_evidence'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'projection' => 'encrypted:array', 'policy_evidence' => 'encrypted:array', 'evaluated_at' => 'immutable_datetime'];
    }
}
