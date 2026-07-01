<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceNote;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceNote>
 */
class MaintenanceNoteFactory extends Factory
{
    protected $model = MaintenanceNote::class;

    public function definition(): array
    {
        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'technician_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
