<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY administrator account.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  Email:     arttesting@sccit.local                                        │
 * │  Password:  Artscc         (display name: Arttesting)                     │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * SAFETY
 *  - Runs ONLY when APP_ENV=local (guarded below); it is a no-op in production,
 *    staging, and the test environment, so it is never deployed as real data.
 *  - Authentication is email-based (FR-AUTH-001) — sign in with the EMAIL above,
 *    not the display name. No username column exists.
 *  - The password is deliberately simple for convenience and is BELOW the
 *    production password policy (FR-AUTH-003). That is acceptable only because
 *    this seeder is local-only and the password is hashed by the model's
 *    'hashed' cast — the plaintext is never stored or logged.
 *
 * TO CHANGE OR REMOVE
 *  - Change: edit the email/first_name/password below and re-run
 *    `php artisan db:seed --class=Database\\Seeders\\DevAdminSeeder`.
 *  - Remove: delete this class and its call in DatabaseSeeder, then delete the
 *    row (`arttesting@sccit.local`) from your local database.
 */
class DevAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $role = Role::query()->where('slug', 'administrator')->firstOrFail();

        User::query()->updateOrCreate(
            ['email' => 'arttesting@sccit.local'],
            [
                'role_id' => $role->id,
                'employee_number' => 'DEV-ADMIN',
                'first_name' => 'Arttesting',
                'last_name' => '',
                'password' => 'Artscc', // hashed by the model's 'hashed' cast
                'email_verified_at' => now(),
                'status' => UserStatus::Active->value,
            ],
        );
    }
}
