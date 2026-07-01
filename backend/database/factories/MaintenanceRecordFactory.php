<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRecord>
 */
class MaintenanceRecordFactory extends Factory
{
    protected $model = MaintenanceRecord::class;

    public function definition(): array
    {
        // CHECK constraint: at least one of pc_unit_id / asset_id must be set.
        return [
            'pc_unit_id' => PcUnit::factory(),
            'technician_id' => User::factory(),
            'maintenance_type_id' => MaintenanceType::factory(),
            'title' => fake()->sentence(4),
            'diagnosis' => fake()->optional()->paragraph(),
            'resolution' => fake()->optional()->paragraph(),
            'downtime_minutes' => fake()->numberBetween(0, 480),
            'labor_hours' => fake()->randomFloat(2, 0, 8),
            'status' => MaintenanceStatus::Scheduled->value,
            'maintenance_date' => now(),
        ];
    }
}
