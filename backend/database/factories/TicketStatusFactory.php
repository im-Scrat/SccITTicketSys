<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TicketStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketStatus>
 */
class TicketStatusFactory extends Factory
{
    protected $model = TicketStatus::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'color' => fake()->hexColor(),
            'description' => fake()->optional()->sentence(),
            'is_default' => false,
            'is_open' => true,
            'is_terminal' => false,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function terminal(): static
    {
        return $this->state(fn (array $attributes) => ['is_open' => false, 'is_terminal' => true]);
    }
}
