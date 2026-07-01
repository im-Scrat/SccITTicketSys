<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'floor_id' => Floor::factory(),
            'room_type' => fake()->randomElement(RoomType::values()),
            'name' => 'Room '.fake()->unique()->bothify('?##'),
            'code' => fake()->unique()->bothify('RM-???##'),
            'room_number' => (string) fake()->numberBetween(100, 499),
            'capacity' => fake()->numberBetween(10, 50),
            'is_active' => true,
        ];
    }

    public function laboratory(): static
    {
        return $this->state(fn (array $attributes) => ['room_type' => RoomType::Laboratory->value]);
    }
}
