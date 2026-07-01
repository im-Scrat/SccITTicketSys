<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomLayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomLayout>
 */
class RoomLayoutFactory extends Factory
{
    protected $model = RoomLayout::class;

    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'version' => 1,
            'width' => fake()->numberBetween(800, 1920),
            'height' => fake()->numberBetween(600, 1080),
            'grid_size' => 20,
            'is_active' => true,
        ];
    }
}
