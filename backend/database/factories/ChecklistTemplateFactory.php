<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistTemplate>
 */
class ChecklistTemplateFactory extends Factory
{
    protected $model = ChecklistTemplate::class;

    public function definition(): array
    {
        return [
            'name' => ucwords(fake()->unique()->words(3, true)),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
