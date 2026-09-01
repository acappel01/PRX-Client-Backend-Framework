<?php

namespace App\Data\PrescribeRx;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class ConsentData extends Data
{
    public function __construct(
        /** Maps to ConsentType enum on the prescribe-rx side. */
        #[Required]
        public int $consent_type,
        #[Required]
        public string $consent_text,
        public string $consent_version = '1.0',
        public ?string $consented_at = null,
        public ?string $ip_address = null,
        /** Their accepted values are click / typed / pad. */
        #[In(['click', 'typed', 'pad'])]
        public string $signature_method = 'click',
        /** Base64 PNG, used with the `pad` method. */
        public ?string $signature_data = null,
    ) {}
}
