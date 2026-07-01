<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Manufacturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Manufacturer>
 */
class ManufacturerFactory extends Factory
{
    protected $model = Manufacturer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->bothify('MFR-???'),
            'website' => fake()->optional()->url(),
            'support_email' => fake()->optional()->companyEmail(),
            'support_phone' => fake()->optional()->phoneNumber(),
        ];
    }
}
