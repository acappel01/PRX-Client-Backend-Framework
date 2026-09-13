<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->numerify('##########'),
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'prx_patient_chart_id' => null,
            'prx_patient_id' => null,
            'prx_chart_collision_flagged' => false,
        ];
    }

    public function withPrxChart(): static
    {
        return $this->state(fn () => [
            'prx_patient_chart_id' => fake()->uuid(),
            'prx_patient_id' => fake()->uuid(),
            // Set, so minting a token in a test does not look it up from the
            // provider. ProviderIdentifiersTest covers the lookup explicitly.
            'prx_patient_number' => 'PAT-'.fake()->unique()->numerify('##########'),
            'prx_chart_verified_at' => now(),
        ]);
    }

    public function withCollision(): static
    {
        return $this->state(fn () => [
            'prx_chart_collision_flagged' => true,
        ]);
    }
}
