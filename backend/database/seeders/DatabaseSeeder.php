<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Reference/lookup data is always seeded (idempotent). Demo data is only
     * seeded outside production. Model events are intentionally NOT muted so
     * the public-uuid generation hooks fire during seeding.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            TicketLookupSeeder::class,
            MaintenanceTypeSeeder::class,
            AiSeeder::class,
            SystemSettingSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
