<?php

namespace App\Settings;

use App\Enums\Patient\TwoFactorPolicy;
use Spatie\LaravelSettings\Settings;

/**
 * Patient portal behaviour that an operator decides per install.
 *
 * `security_events_retention_days`: how long a patient's security history
 * (sign-ins with IP and browser, sessions revoked, links used) is kept before
 * the nightly prune deletes it. IP addresses identify people, so this is a
 * retention decision, not a technical default.
 *
 * `session_idle_minutes` / `session_max_hours`: a patient session ends after
 * that long unused, and always after that long in total. HIPAA's automatic
 * logoff (164.312(a)(2)(iii)) names no number; the defaults are NIST SP 800-63B
 * rev 3's AAL2 reauthentication values. Write the chosen values into the
 * install's security policy — an auditor tests the two against each other.
 */
class PortalSettings extends Settings
{
    public int $security_events_retention_days;

    public int $session_idle_minutes;

    public int $session_max_hours;

    /** A TwoFactorPolicy value. */
    public string $two_factor_policy;

    public function twoFactorPolicy(): TwoFactorPolicy
    {
        return TwoFactorPolicy::tryFrom($this->two_factor_policy) ?? TwoFactorPolicy::Off;
    }

    public static function group(): string
    {
        return 'portal';
    }
}
