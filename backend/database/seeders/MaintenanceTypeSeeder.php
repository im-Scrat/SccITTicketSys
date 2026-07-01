<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MaintenanceType;
use Illuminate\Database\Seeder;

class MaintenanceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Preventive Maintenance', 'slug' => 'preventive', 'is_preventive' => true],
            ['name' => 'Corrective Repair', 'slug' => 'corrective', 'is_preventive' => false],
            ['name' => 'Hardware Upgrade', 'slug' => 'hardware-upgrade', 'is_preventive' => false],
            ['name' => 'Inspection', 'slug' => 'inspection', 'is_preventive' => true],
            ['name' => 'Cleaning', 'slug' => 'cleaning', 'is_preventive' => true],
        ];

        foreach ($types as $type) {
            MaintenanceType::query()->updateOrCreate(['slug' => $type['slug']], [...$type, 'is_active' => true]);
        }
    }
}
