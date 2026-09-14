<?php

namespace App\Data\Customers;

use Spatie\LaravelData\Data;

class UpsertCustomerAddressData extends Data
{
    /** @param array<string, string|null> $address */
    public function __construct(public string $kind, public array $address, public bool $is_default = false) {}

    public static function rules(): array
    {
        return [
            'kind' => ['required', 'in:shipping,billing'],
            'address' => ['required', 'array:recipient_name,line1,line2,city,region,postal_code,country_code'],
            'address.recipient_name' => ['nullable', 'string', 'max:200'],
            'address.line1' => ['required', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.region' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:32'],
            'address.country_code' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'is_default' => ['boolean'],
        ];
    }
}
