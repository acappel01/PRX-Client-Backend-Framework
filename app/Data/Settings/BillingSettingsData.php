<?php

namespace App\Data\Settings;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class BillingSettingsData extends Data
{
    public function __construct(
        #[Required, In(['prx', 'local'])]
        public string $checkout_path,

        /**
         * WHO TAKES THE MONEY — deliberately part of this DTO rather than only
         * on the settings class. A Filament settings page saves through this
         * action, so a property missing here renders, accepts a value, reports
         * success and writes NOTHING. That has happened three times in this
         * repo; the round-trip test beside it is the guard.
         */
        #[In(['provider', 'capture_then_intake', 'authorize_then_capture'])]
        public string $payment_collector = 'provider',

        public bool $upsells_enabled = true,

        #[IntegerType, Min(1), Max(12)]
        public int $upsells_limit = 4,
    ) {}
}
