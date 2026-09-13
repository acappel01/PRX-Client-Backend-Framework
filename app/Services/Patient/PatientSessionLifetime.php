<?php

namespace App\Services\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Settings\PortalSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * How long a patient session lives: an idle limit and an absolute cap, both
 * operator settings (Settings → Patient portal).
 *
 * ── Before this, a session never ended ──────────────────────────────────────
 *
 * `sanctum.expiration` is null and no patient token carried `expires_at`, so a
 * sign-in on a shared or lost phone stayed live until someone signed out. The
 * global Sanctum setting is not used to fix it: it would also expire the
 * storefront's machine tokens, which must not idle out.
 *
 * ── Enforced when a token is used, on both limits ──────────────────────────
 *
 * Sanctum calls `allows()` after its own checks on every request. A patient
 * token is refused once it is older than the cap or has gone unused longer than
 * the idle limit — measured against the CURRENT settings, so shortening them
 * applies to live sessions on their next request. `expires_at` is also stamped
 * at issue (cap from issue time), which lets `sanctum:prune-expired` remove
 * abandoned tokens that are never presented again. Because that stamp also
 * holds, LENGTHENING the cap reaches only sessions signed in afterwards.
 *
 * An expired token is deleted and the expiry recorded once: the delete is
 * conditional, so two concurrent requests on the same token write one event.
 * Sanctum updates `last_used_at` only after a token is accepted, so a refused
 * request can never extend the session it was refused for.
 */
class PatientSessionLifetime
{
    public const REASON_IDLE = 'idle';

    public const REASON_MAX_AGE = 'max_age';

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PatientSecurityLog $log,
    ) {}

    /** `expires_at` for a token issued now: the absolute cap. */
    public function expiresAt(): CarbonInterface
    {
        return Date::now()->addHours($this->maxHours());
    }

    public function idleMinutes(): int
    {
        return max(1, $this->settings->session_idle_minutes);
    }

    public function maxHours(): int
    {
        return max(1, $this->settings->session_max_hours);
    }

    /**
     * Sanctum's access-token authentication callback.
     *
     * Non-patient tokens pass through untouched, with whatever Sanctum decided.
     */
    public function allows(PersonalAccessToken $token, bool $isValid): bool
    {
        if ($token->tokenable_type !== (new Patient)->getMorphClass()) {
            return $isValid;
        }

        // Sanctum has already refused a token past its `expires_at` — the cap
        // stamped at issue — before this runs. It is still an expiry, and must
        // still be recorded and removed, so it is judged here rather than
        // passed through as a silent 401. Refused for any other reason, it is.
        $pastStampedCap = $token->expires_at !== null && $token->expires_at->isPast();

        if (! $isValid && ! $pastStampedCap) {
            return false;
        }

        $reason = $this->expiryReason($token) ?? ($pastStampedCap ? self::REASON_MAX_AGE : null);

        if ($reason === null) {
            return true;
        }

        $this->expire($token, $reason);

        return false;
    }

    /** Why this token is no longer usable, or null while it is. */
    public function expiryReason(PersonalAccessToken $token): ?string
    {
        $now = Date::now();

        if ($token->created_at === null || $token->created_at->lte($now->copy()->subHours($this->maxHours()))) {
            return self::REASON_MAX_AGE;
        }

        $lastActivity = $token->last_used_at ?? $token->created_at;

        if ($lastActivity->lte($now->copy()->subMinutes($this->idleMinutes()))) {
            return self::REASON_IDLE;
        }

        return null;
    }

    /**
     * When this session will end: the idle deadline from its last use, and the
     * cap — the earlier of the current setting and the stamp from issue.
     *
     * @return array{idle_expires_at: CarbonInterface, expires_at: CarbonInterface}
     */
    public function deadlines(PersonalAccessToken $token): array
    {
        $lastActivity = $token->last_used_at ?? $token->created_at;

        $cap = $token->created_at->copy()->addHours($this->maxHours());

        if ($token->expires_at !== null && $token->expires_at->lt($cap)) {
            $cap = $token->expires_at->copy();
        }

        return [
            'idle_expires_at' => $lastActivity->copy()->addMinutes($this->idleMinutes()),
            'expires_at' => $cap,
        ];
    }

    private function expire(PersonalAccessToken $token, string $reason): void
    {
        $deleted = PersonalAccessToken::query()->whereKey($token->getKey())->delete();

        if ($deleted !== 1) {
            return;
        }

        $patient = $token->tokenable;

        $this->log->record(
            SecurityEventType::SessionExpired,
            patient: $patient instanceof Patient ? $patient : null,
            // Who presented the dead token — the one interesting fact about it.
            // Operators see it; the patient resource strips it on system events.
            client: app()->runningInConsole() ? null : RequestContext::fromRequest(request()),
            actor: SecurityEventActor::System,
            tokenId: $token->getKey(),
            context: ['reason' => $reason],
        );
    }
}
