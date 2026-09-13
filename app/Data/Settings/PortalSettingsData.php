<?php

namespace App\Data\Settings;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class PortalSettingsData extends Data
{
    public const RETENTION_MIN_DAYS = 30;

    public const RETENTION_MAX_DAYS = 2555;

    public function __construct(
        // No "keep forever": identifying data needs an end date.
        #[Required, Between(self::RETENTION_MIN_DAYS, self::RETENTION_MAX_DAYS)]
        public int $security_events_retention_days,
    ) {}
}
