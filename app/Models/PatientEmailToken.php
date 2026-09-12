<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use link emailed to a patient. See the migration for the design.
 *
 * Nothing here is mass-assignable from a request: every row is written by
 * `RequestClaimLinkAction` from values it derived itself.
 */
class PatientEmailToken extends Model
{
    public const PURPOSE_CLAIM = 'claim';

    /** Minutes a claim link stays usable. The patient is at the screen when they ask. */
    public const CLAIM_TTL_MINUTES = 60;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * A fresh plain token: 32 random bytes, base64url, 43 characters.
     *
     * The plain value is returned to the caller for the email and never
     * persisted — store `hash()` of it.
     */
    public static function newPlainToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** The exact shape `newPlainToken()` produces. Anything else is not worth a query. */
    public static function looksValid(mixed $plain): bool
    {
        return is_string($plain) && preg_match('/^[A-Za-z0-9_-]{43}$/', $plain) === 1;
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('consumed_at');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    public function sentToMatches(string $email): bool
    {
        return hash_equals($this->sent_to, Str::lower($email));
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
