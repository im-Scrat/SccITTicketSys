<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProblemArea;
use App\Models\TicketCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketCategory>
 */
class TicketCategoryFactory extends Factory
{
    protected $model = TicketCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'problem_area' => ProblemArea::Other->value,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }
}
