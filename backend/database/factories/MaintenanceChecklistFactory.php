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
            'is_required' => false,
            'sort_order' => 0,
            'is_completed' => fake()->boolean(),
        ];
    }

    /** A blocking item: completion is refused while this one is unticked. */
    public function required(): static
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'is_completed' => false,
            'completed_by' => null,
            'completed_at' => null,
        ]);
    }
}
