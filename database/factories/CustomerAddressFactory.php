<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'kind' => 'shipping',
            'address' => [
                'recipient_name' => fake()->name(),
                'line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'region' => fake()->state(),
                'postal_code' => fake()->postcode(),
                'country_code' => fake()->countryCode(),
            ],
            'is_default' => false,
        ];
    }
}
