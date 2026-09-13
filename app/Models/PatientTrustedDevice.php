<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser a patient trusted after a two-step sign-in. See TrustedDevices. */
class PatientTrustedDevice extends Model
{
    use HasUuids, MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'patient_id', 'token_hash', 'label', 'created_ip', 'last_used_at',
        'last_used_ip', 'expires_at', 'revoked_at', 'revoked_reason',
    ];

    protected $hidden = ['token_hash'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** Dead for a month: expired or revoked. */
    public function prunable(): Builder
    {
        return static::query()->where(fn ($q) => $q
            ->where('expires_at', '<', now()->subDays(30))
            ->orWhere('revoked_at', '<', now()->subDays(30)));
    }
}
