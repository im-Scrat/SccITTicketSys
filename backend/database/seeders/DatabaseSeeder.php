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
            // After the types: each template binds itself to a type as that
            // type's default checklist (FR-MNT-004).
            ChecklistTemplateSeeder::class,
            AiSeeder::class,
            SystemSettingSeeder::class,
            AdminUserSeeder::class,
        ]);

        // Local-only developer admin (self-guarded to APP_ENV=local).
        $this->call(DevAdminSeeder::class);

        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
