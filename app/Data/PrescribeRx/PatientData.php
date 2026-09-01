<?php

namespace App\Data\PrescribeRx;

use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class PatientData extends Data
{
    public function __construct(
        #[Required, Max(120)]
        public string $first_name,
        #[Required, Max(120)]
        public string $last_name,
        #[Required, Email, Max(255)]
        public string $email,
        #[Required, Date]
        public string $date_of_birth,
        #[Max(32)]
        public ?string $phone = null,
        /**
         * Their contract accepts "male" / "female" / "other" only (or 1/2/3).
         * Our own lead form also offers "prefer_not_to_say", which is NOT a
         * value they take — `SubmitPrescribeRxCheckoutAction::mapGender()`
         * drops it rather than sending a 422. Do not widen this list to match
         * the lead form; the two vocabularies are deliberately different.
         */
        #[In(['male', 'female', 'other'])]
        public ?string $gender = null,
        public ?AddressData $address = null,
    ) {}
}
