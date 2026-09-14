<?php

namespace App\Models\Attribution;

use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Immutable local event/outbox entry. Its existence does not indicate vendor delivery. */
class CanonicalEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'lead_id', 'customer_id', 'dedupe_key', 'lead', 'customer'];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'payload' => 'encrypted:array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            if ($event->isDirty()) {
                throw ValidationException::withMessages(['event' => 'Canonical events cannot be changed. Record a separately identified correction.']);
            }
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['event' => 'Canonical event history cannot be deleted through ordinary model operations.']);
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
