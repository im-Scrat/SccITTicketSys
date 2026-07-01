<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TicketPriority;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketPriority>
 */
class TicketPriorityFactory extends Factory
{
    protected $model = TicketPriority::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'level' => fake()->unique()->numberBetween(1, 100000),
            'color' => fake()->hexColor(),
            'response_time_minutes' => fake()->numberBetween(15, 240),
            'resolution_time_minutes' => fake()->numberBetween(240, 2880),
            'is_active' => true,
        ];
    }
}
