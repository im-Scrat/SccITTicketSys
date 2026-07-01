<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->streetName().' Building',
            'code' => fake()->unique()->bothify('BLDG-??##'),
            'description' => fake()->optional()->sentence(),
            'address' => fake()->optional()->address(),
            'is_active' => true,
        ];
    }
}
