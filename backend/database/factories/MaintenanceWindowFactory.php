<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+1 month');

        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->modify('+'.fake()->numberBetween(1, 6).' hours'),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
