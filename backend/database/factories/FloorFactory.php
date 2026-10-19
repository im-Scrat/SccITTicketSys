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

    /** Next floor number handed out in this process. */
    private static int $next = 0;

    public function definition(): array
    {
        // A counter, not a random pick: floors are unique per building
        // (building_id, floor_number), and two random numbers from 1–10 for the
        // same building collided often enough to fail CI (LocationArchiveTest).
        // Cycles within 1–200 so the value stays a plausible floor.
        $number = (self::$next++ % 200) + 1;

        return [
            'building_id' => Building::factory(),
            'floor_number' => $number,
            'name' => "Floor {$number}",
            'description' => fake()->optional()->sentence(),
        ];
    }
}
