<?php

namespace App\Data\PrescribeRx;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class AddressData extends Data
{
    public function __construct(
        #[Required, Max(255)]
        public string $street,
        #[Required, Max(128)]
        public string $city,
        #[Required, Max(2)]
        public string $state,
        #[Required, Max(16)]
        #[MapOutputName('zip')]
        public string $zip,
        #[Max(2)]
        public string $country = 'US',
        /**
         * Optional second address line. Last in the signature so the existing
         * positional order is untouched — construct with named arguments.
         */
        #[Max(255)]
        public ?string $street2 = null,
    ) {}
}
