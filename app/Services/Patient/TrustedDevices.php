<?php

namespace App\Services\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use App\Models\PatientTrustedDevice;
use App\Settings\PortalSettings;
use Carbon\CarbonInterface;

/**
 * "Trust this browser": after a two-step sign-in, a browser may skip the CODE at
 * later sign-ins for `portal.trusted_device_days` (sliding with use). Never the
 * password — the password step always runs first, and a trusted browser only
 * changes what happens after it.
 *
 * ── The token ────────────────────────────────────────────────────────────────
 *
 * 256 random bits, handed to the portal once (it keeps it in an httpOnly cookie)
 * and stored here only as sha256, bound to one patient. Presented with a
 * password, it is honoured only for THAT patient, only while unrevoked and
 * unexpired, and only while the install allows trusting at all (days > 0).
 *
 * ── Revocation ───────────────────────────────────────────────────────────────
 *
 * Every trusted browser on an account is revoked when anything suggests the
 * account's security changed hands: a password reset, a first email
 * verification, two-step verification turned off, reset by support or moved to a
 * new authenticator, "sign out everywhere", and account deletion. A patient can
 * also revoke one or all from Record → Two-step verification.
 */
class TrustedDevices
{
    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PatientSecurityLog $log,
    ) {}

    public function days(): int
    {
        return max(0, $this->settings->trusted_device_days);
    }

    public function enabled(): bool
    {
        return $this->days() > 0;
    }

    /**
     * Trust the browser this request came from.
     *
     * @return array{token: string, expires_at: CarbonInterface}|null null when trusting is off
     */
    public function trust(Patient $patient, ?RequestContext $client): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $plain = PatientEmailToken::newPlainToken();

        $device = PatientTrustedDevice::create([
            'patient_id' => $patient->getKey(),
            'token_hash' => PatientEmailToken::hash($plain),
            'label' => self::label($client?->userAgent),
            'created_ip' => $client?->ip,
            'last_used_at' => now(),
            'last_used_ip' => $client?->ip,
            'expires_at' => now()->addDays($this->days()),
        ]);

        $this->log->record(SecurityEventType::DeviceTrusted, patient: $patient, client: $client);

        return ['token' => $plain, 'expires_at' => $device->expires_at];
    }

    /**
     * The trusted device this token names for this patient, refreshed — or null.
     * Slides the expiry forward, never past the current setting from now.
     */
    public function recognise(Patient $patient, mixed $token, ?RequestContext $client): ?PatientTrustedDevice
    {
        if (! $this->enabled() || ! PatientEmailToken::looksValid($token)) {
            return null;
        }

        $device = PatientTrustedDevice::query()
            ->active()
            ->where('patient_id', $patient->getKey())
            ->where('token_hash', PatientEmailToken::hash($token))
            ->first();

        if ($device === null) {
            return null;
        }

        $refreshed = PatientTrustedDevice::query()
            ->whereKey($device->getKey())
            ->whereNull('revoked_at')
            ->update([
                'last_used_at' => now(),
                'last_used_ip' => $client?->ip,
                'expires_at' => now()->addDays($this->days()),
            ]);

        return $refreshed === 1 ? $device->refresh() : null;
    }

    /**
     * Revoke every trusted browser on the account. Records one event when any
     * were live; returns how many.
     */
    public function revokeAll(
        Patient $patient,
        string $reason,
        ?RequestContext $client = null,
        // The patient by default, like PatientSecurityLog: most revocations are
        // the patient's own act (reset, disable, replace, claim). Operator and
        // system callers pass their actor explicitly.
        SecurityEventActor $actor = SecurityEventActor::Patient,
        ?int $actorUserId = null,
    ): int {
        $revoked = PatientTrustedDevice::query()
            ->where('patient_id', $patient->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        if ($revoked > 0) {
            $this->log->record(
                SecurityEventType::DeviceRevoked,
                patient: $patient,
                client: $client,
                actor: $actor,
                actorUserId: $actorUserId,
                context: ['reason' => $reason, 'revoked' => $revoked],
            );
        }

        return $revoked;
    }

    /** Revoke one of the patient's own trusted browsers by its public uuid. */
    public function revokeOne(Patient $patient, string $uuid, ?RequestContext $client): bool
    {
        $revoked = PatientTrustedDevice::query()
            ->where('patient_id', $patient->getKey())
            ->where('uuid', $uuid)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'patient']);

        if ($revoked === 1) {
            $this->log->record(SecurityEventType::DeviceRevoked, patient: $patient, client: $client, context: ['reason' => 'patient', 'revoked' => 1]);
        }

        return $revoked === 1;
    }

    /** "Safari on iPhone" — derived here, so nothing a person typed becomes a label. */
    public static function label(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS X') => 'Mac',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            default => $browser ?? $system ?? 'Unknown browser',
        };
    }
}
