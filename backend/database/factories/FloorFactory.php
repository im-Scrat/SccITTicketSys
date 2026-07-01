<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Building;
use App\Models\Floor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Floor>
 */
class FloorFactory extends Factory
{
    protected $model = Floor::class;

    public function definition(): array
    {
        $number = fake()->numberBetween(1, 10);

        return [
            'building_id' => Building::factory(),
            'floor_number' => $number,
            'name' => "Floor {$number}",
            'description' => fake()->optional()->sentence(),
        ];
    }
}
