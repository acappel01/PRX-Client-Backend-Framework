<?php

namespace App\Models;

use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Settings\PortalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One entry in a patient account's security history. Write through
 * `App\Services\Patient\PatientSecurityLog`, never directly.
 *
 * ── Append-only ─────────────────────────────────────────────────────────────
 *
 * Updates and deletes through the model throw. That makes editing history
 * impossible THROUGH THE APPLICATION, not impossible: the database user can
 * still run SQL. The HMAC is what makes such an edit visible.
 *
 * The one sanctioned removal is retention. This model is `MassPrunable` rather
 * than `Prunable` for exactly that reason — `Prunable` deletes row by row
 * through `delete()`, which the guard below would refuse, while `MassPrunable`
 * issues one query-level delete of whole rows. Nothing is ever redacted in
 * place: an UPDATE would contradict the guard and break the signature.
 *
 * ── Integrity ───────────────────────────────────────────────────────────────
 *
 * `integrity` = HMAC-SHA256 over a canonical JSON of the immutable columns,
 * keyed from APP_KEY. `patient_id` is left out on purpose — its foreign key
 * nulls it when a patient is force-deleted, which is a legitimate change — and
 * `patient_uuid` is signed in its place. Verification also tries
 * `app.previous_keys`, so rotating APP_KEY does not make history look forged.
 *
 * It detects an EDITED row. It does not detect a DELETED one; that needs a hash
 * chain, deferred because every sign-in on the install would queue behind the
 * lock that keeps a chain unforked (docs/portal/dev.md).
 */
class PatientSecurityEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Never prune younger than this, whatever the setting says. */
    public const MIN_RETENTION_DAYS = 30;

    protected $fillable = [
        'patient_id',
        'patient_uuid',
        'subject_hash',
        'type',
        'actor_type',
        'actor_user_id',
        'token_id',
        'ip_address',
        'user_agent',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SecurityEventType::class,
            'actor_type' => SecurityEventActor::class,
            'actor_user_id' => 'integer',
            'token_id' => 'integer',
            'context' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PatientSecurityEvent $event): void {
            // Whole seconds, so the instance in memory equals the row that is
            // stored (Eloquent writes `Y-m-d H:i:s`). The signature formats the
            // same way, so it never depends on a fraction either store drops.
            $event->occurred_at = ($event->occurred_at ?? now())->copy()->startOfSecond();

            if ($event->context === []) {
                $event->context = null;
            }

            $event->integrity = $event->computeIntegrity((string) config('app.key'));
        });

        static::updating(function (): void {
            throw new RuntimeException('Patient security events are append-only. Record a new event instead of editing this one.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Patient security events are append-only and cannot be deleted. Retention removes them.');
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function prunable(): Builder
    {
        $days = max(self::MIN_RETENTION_DAYS, (int) app(PortalSettings::class)->security_events_retention_days);

        return static::query()->where('occurred_at', '<', now()->subDays($days));
    }

    /** Whether the stored signature matches the row under the current or any previous APP_KEY. */
    public function hasValidIntegrity(): bool
    {
        $keys = array_filter([config('app.key'), ...(array) config('app.previous_keys', [])]);

        foreach ($keys as $key) {
            if (hash_equals($this->computeIntegrity((string) $key), (string) $this->integrity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A keyed hash of an email address, so attempts against one address can be
     * counted without storing it. Plain sha256 of an address is reversible by
     * dictionary; this is not without APP_KEY. A key rotation starts new hashes,
     * which only splits the count at the rotation.
     */
    public static function subjectHash(string $email): string
    {
        return hash_hmac('sha256', Str::lower(trim($email)), self::derivedKey((string) config('app.key'), 'subject'));
    }

    public function computeIntegrity(string $appKey): string
    {
        return hash_hmac('sha256', $this->canonicalPayload(), self::derivedKey($appKey, 'integrity'));
    }

    private function canonicalPayload(): string
    {
        $occurredAt = $this->occurred_at instanceof Carbon
            ? $this->occurred_at->copy()->utc()->format('Y-m-d H:i:s')
            : null;

        return json_encode([
            'patient_uuid' => $this->patient_uuid,
            'subject_hash' => $this->subject_hash,
            'type' => $this->type instanceof SecurityEventType ? $this->type->value : $this->type,
            'actor_type' => $this->actor_type instanceof SecurityEventActor ? $this->actor_type->value : $this->actor_type,
            'actor_user_id' => $this->actor_user_id === null ? null : (int) $this->actor_user_id,
            'token_id' => $this->token_id === null ? null : (int) $this->token_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            // Key order is normalised because MySQL's JSON type reorders keys on
            // storage; the row read back must serialise exactly as it was signed.
            'context' => self::sortKeys($this->context ?: null),
            'occurred_at' => $occurredAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function derivedKey(string $appKey, string $purpose): string
    {
        return hash_hmac('sha256', "patient-security-events:{$purpose}", $appKey, true);
    }

    private static function sortKeys(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
