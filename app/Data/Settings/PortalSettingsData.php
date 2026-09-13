<?php

namespace App\Data\Settings;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class PortalSettingsData extends Data
{
    public const RETENTION_MIN_DAYS = 30;

    public const RETENTION_MAX_DAYS = 2555;

    public const IDLE_MIN_MINUTES = 5;

    public const IDLE_MAX_MINUTES = 240;

    public const MAX_MIN_HOURS = 1;

    public const MAX_MAX_HOURS = 720;

    public const TRUSTED_DEVICE_MAX_DAYS = 90;

    public function __construct(
        // No "keep forever": identifying data needs an end date.
        #[Required, Between(self::RETENTION_MIN_DAYS, self::RETENTION_MAX_DAYS)]
        public int $security_events_retention_days,
        // No "never": a session that cannot end is the defect this replaced.
        #[Required, Between(self::IDLE_MIN_MINUTES, self::IDLE_MAX_MINUTES)]
        public int $session_idle_minutes,
        #[Required, Between(self::MAX_MIN_HOURS, self::MAX_MAX_HOURS)]
        public int $session_max_hours,
        #[Required, In(['off', 'optional', 'required'])]
        public string $two_factor_policy,
        #[Required, Between(0, self::TRUSTED_DEVICE_MAX_DAYS)]
        public int $trusted_device_days,
    ) {}
}
