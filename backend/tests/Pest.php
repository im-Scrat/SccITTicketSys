<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests boot the framework (config available) but do not touch the database.
pest()->extend(TestCase::class)->in('Unit');

// Treat API requests in the auth suite as coming from the SPA origin so Sanctum
// marks them stateful and starts the session (mirrors real first-party cookie
// auth). CSRF is skipped automatically while running tests.
pest()->beforeEach(function () {
    $this->withHeader('Origin', (string) config('app.url'));
})->in('Feature/Auth', 'Feature/Users');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Seed the RBAC baseline (system roles + permission matrix) required by the
 * authentication/authorization tests.
 */
function seedRbac(): void
{
    test()->seed([
        RoleSeeder::class,
        PermissionSeeder::class,
    ]);
}

/**
 * Create a user for a given role slug (RBAC must be seeded first).
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(string $roleSlug, array $attributes = []): User
{
    $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

    return User::factory()->create([...['role_id' => $role->id], ...$attributes]);
}
