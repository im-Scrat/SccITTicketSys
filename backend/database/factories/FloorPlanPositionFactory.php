<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\RoomLayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FloorPlanPosition>
 */
class FloorPlanPositionFactory extends Factory
{
    protected $model = FloorPlanPosition::class;

    public function definition(): array
    {
        return [
            'room_layout_id' => RoomLayout::factory(),
            'pc_unit_id' => PcUnit::factory(),
            'pos_x' => fake()->numberBetween(0, 1800),
            'pos_y' => fake()->numberBetween(0, 1000),
            'rotation' => 0,
            'z_index' => 0,
        ];
    }
}
