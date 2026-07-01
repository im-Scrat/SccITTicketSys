<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceChecklist;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceChecklist>
 */
class MaintenanceChecklistFactory extends Factory
{
    protected $model = MaintenanceChecklist::class;

    public function definition(): array
    {
        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'item_label' => fake()->sentence(4),
            'is_completed' => fake()->boolean(),
        ];
    }
}
