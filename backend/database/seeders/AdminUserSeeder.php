<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first administrator on a fresh install — and only then.
 *
 * This used to `updateOrCreate`, so any later `db:seed --force` (the
 * documented "first install" command, easy to re-run against a live database)
 * silently reset that account's password to ADMIN_PASSWORD — or to "password"
 * when the variable was missing — and re-activated it. An existing account is
 * now left exactly as its owner left it; changing it is a job for the user
 * administration screens, which are audited.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@school.test');

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->command?->info("Administrator {$email} already exists — left unchanged.");

            return;
        }

        $role = Role::query()->where('slug', 'administrator')->firstOrFail();

        User::query()->create([
            'email' => $email,
            'role_id' => $role->id,
            'employee_number' => 'ADMIN-0001',
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'password' => Hash::make((string) env('ADMIN_PASSWORD', 'password')),
            'email_verified_at' => now(),
            'status' => UserStatus::Active->value,
        ]);
    }
}
