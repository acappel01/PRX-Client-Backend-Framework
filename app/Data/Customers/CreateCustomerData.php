<?php

namespace App\Data\Customers;

use Spatie\LaravelData\Data;

class CreateCustomerData extends Data
{
    public function __construct(
        public string $first_name,
        public string $last_name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $date_of_birth = null,
        public ?string $provider_environment = null,
        public ?string $prx_patient_chart_id = null,
        public ?string $prx_patient_id = null,
        public ?string $prx_patient_number = null,
    ) {}

    public static function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'provider_environment' => ['nullable', 'in:sandbox,production'],
            'prx_patient_chart_id' => ['nullable', 'string', 'max:64'],
            'prx_patient_id' => ['nullable', 'string', 'max:64'],
            'prx_patient_number' => ['nullable', 'string', 'max:64'],
        ];
    }
}
