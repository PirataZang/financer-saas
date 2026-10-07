<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('479########'),
            'status' => fake()->randomElement(CustomerStatus::cases()),
            'active' => true,
            'zip_code' => fake()->numerify('89######'),
            'street' => fake()->streetName(),
            'number' => fake()->buildingNumber(),
            'complement' => fake()->optional()->secondaryAddress(),
            'neighborhood' => fake()->word(),
            'reference' => fake()->optional()->sentence(3),
            'city' => fake()->city(),
            'state' => 'SC',
            'country' => 'Brasil',
        ];
    }
}
