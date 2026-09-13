<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A password accepted, a second factor not yet given. See the migration and
 * CompleteTwoFactorLoginAction.
 *
 * The plain value exists only in the login response and the portal's
 * short-lived cookie; this row holds its sha256. It is never a Sanctum token and
 * can never be presented as one.
 */
class PatientAuthChallenge extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const PURPOSE_LOGIN = 'login';

    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'patient_id', 'purpose', 'token_hash', 'attempts', 'expires_at',
        'consumed_at', 'voided_at', 'device_name', 'requested_ip', 'user_agent',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->voided_at === null
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at->isFuture();
    }

    /** Challenges live five minutes; a day is ample to keep the dead ones for debugging. */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    /** Void every open login challenge for a patient — password reset, two-factor removal, deletion. */
    public static function voidOutstandingFor(Patient $patient): void
    {
        static::query()
            ->where('patient_id', $patient->getKey())
            ->whereNull('consumed_at')
            ->whereNull('voided_at')
            ->update(['voided_at' => now()]);
    }
}
