<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HardwareComponent;
use App\Models\HardwareReplacement;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HardwareReplacement>
 */
class HardwareReplacementFactory extends Factory
{
    protected $model = HardwareReplacement::class;

    public function definition(): array
    {
        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'old_component_id' => HardwareComponent::factory(),
            'new_component_id' => HardwareComponent::factory(),
            'quantity' => 1,
            'replacement_reason' => fake()->sentence(),
            'warranty_months' => fake()->randomElement([0, 6, 12, 24, 36]),
            'replaced_at' => now(),
        ];
    }
}
