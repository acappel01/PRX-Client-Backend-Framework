<?php

namespace App\Models\Attribution;

/** Stable destination reservation only. No state here authorizes transmission. */
class CanonicalDelivery extends AppendOnlyRecord
{
    protected $hidden = ['canonical_event_id', 'integration_instance_id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
