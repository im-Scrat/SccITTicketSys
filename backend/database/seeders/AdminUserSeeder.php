<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->where('slug', 'administrator')->firstOrFail();

        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@school.test')],
            [
                'role_id' => $role->id,
                'employee_number' => 'ADMIN-0001',
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'email_verified_at' => now(),
                'status' => UserStatus::Active->value,
            ],
        );
    }
}
