<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Administrator', 'slug' => 'administrator', 'description' => 'Full system access and configuration.'],
            ['name' => 'Technician', 'slug' => 'technician', 'description' => 'Handles tickets, maintenance and asset servicing.'],
            ['name' => 'Teacher', 'slug' => 'teacher', 'description' => 'Reports issues and tracks their tickets.'],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                [...$role, 'is_system' => true],
            );
        }
    }
}
