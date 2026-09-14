<?php

namespace App\Data\Customers;

use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

/** Contact-only edit; provider references and credentials have separate writers. */
class UpdateCustomerData extends Data
{
    public function __construct(
        public string $first_name,
        public string $last_name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $date_of_birth = null,
    ) {}

    public static function rules(): array
    {
        return Arr::only(CreateCustomerData::rules(), ['first_name', 'last_name', 'email', 'phone', 'date_of_birth']);
    }
}
