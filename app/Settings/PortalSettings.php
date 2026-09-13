<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Patient portal behaviour that an operator decides per install.
 *
 * `security_events_retention_days`: how long a patient's security history
 * (sign-ins with IP and browser, sessions revoked, links used) is kept before
 * the nightly prune deletes it. IP addresses identify people, so this is a
 * retention decision, not a technical default.
 */
class PortalSettings extends Settings
{
    public int $security_events_retention_days;

    public static function group(): string
    {
        return 'portal';
    }
}
