<?php

namespace App\Models\Attribution;

/** Original submission evidence; never affiliate credit or ownership proof. */
class AttributionTouchpoint extends AppendOnlyRecord
{
    protected $hidden = ['source_tuple', 'canonical_event_id'];

    protected function casts(): array
    {
        return ['source_tuple' => 'encrypted:array', 'recorded_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime'];
    }
}
