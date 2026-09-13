<?php

namespace App\Models\Commerce;

use App\Enums\EncounterStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Encounter extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'lead_id',
        'prescribe_rx_encounter_id',
        'prescribe_rx_patient_id',
        'prescribe_rx_encounter_type_id',
        'status',
        'provider_status',
        'provider_status_at',
        'submitted_at',
        'reviewed_at',
        'completed_at',
        'cancelled_at',
        'total_amount',
        'is_sandbox',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => EncounterStatus::class,
            'provider_status_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'is_sandbox' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Encounter $encounter): void {
            if (blank($encounter->uuid)) {
                $encounter->uuid = (string) Str::uuid();
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
