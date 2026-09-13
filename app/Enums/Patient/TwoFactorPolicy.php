<?php

namespace App\Enums\Patient;

/**
 * Whether patients use two-step verification (Settings → Patient portal).
 *
 * `Off` stops OFFERING it — an account whose owner already turned it on is still
 * challenged and can still manage or remove it. A switch in the admin must never
 * lower an account below what its owner chose.
 */
enum TwoFactorPolicy: string
{
    case Off = 'off';
    case Optional = 'optional';
    case Required = 'required';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off — not offered',
            self::Optional => 'Optional — offered to every patient',
            self::Required => 'Required — patients must set it up',
        };
    }
}
