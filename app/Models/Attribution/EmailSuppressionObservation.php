<?php

namespace App\Models\Attribution;

class EmailSuppressionObservation extends AppendOnlyRecord
{
    protected $hidden = ['lead_id', 'integration_instance_id', 'evidence'];

    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array', 'started_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
