<?php

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\AssignmentStatus;
use App\Enums\PermissionGrantType;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
})->in('Feature/Auth', 'Feature/Users', 'Feature/Locations', 'Feature/Dashboard', 'Feature/Assets', 'Feature/Tickets', 'Feature/Maintenance', 'Feature/Qr', 'Feature/WorkSupport');

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

/**
 * A ticket wired to the **seeded** lookups rather than the factory-invented
 * ones. `TicketFactory` creates a fresh status/priority/category per ticket,
 * which is fine in isolation but useless for lifecycle work — the transition map
 * is keyed on the seeded slugs. Requires `TicketLookupSeeder`.
 *
 * @param  array<string, mixed>  $overrides
 */
function ticketFor(User $reporter, string $statusSlug = 'open', array $overrides = []): Ticket
{
    return Ticket::factory()->create([
        'reporter_id' => $reporter->id,
        'current_status_id' => TicketStatus::query()->where('slug', $statusSlug)->value('id'),
        'priority_id' => TicketPriority::query()->where('slug', 'medium')->value('id'),
        'category_id' => TicketCategory::query()->where('slug', 'hardware')->value('id'),
        ...$overrides,
    ]);
}

/** A seeded ticket status by slug. */
function ticketStatus(string $slug): TicketStatus
{
    return TicketStatus::query()->where('slug', $slug)->firstOrFail();
}

/** Put a technician on a ticket in a given assignment state. */
function assign(Ticket $ticket, User $technician, AssignmentStatus $status): TechnicianAssignment
{
    return TechnicianAssignment::factory()->create([
        'ticket_id' => $ticket->id,
        'technician_id' => $technician->id,
        'status' => $status->value,
    ]);
}

/**
 * A maintenance record wired to a **seeded** maintenance type rather than a
 * factory-invented one, and owned by a named technician.
 *
 * `MaintenanceTypeFactory` invents a type per record, which is fine in
 * isolation and useless for the checklist and preventive-vs-corrective rules —
 * both are keyed on the seeded slugs. Requires `MaintenanceTypeSeeder`.
 *
 * `created_by` defaults to the same person as `technician_id` because that is
 * the shape a technician-initiated record has; pass it explicitly to model an
 * administrator scheduling work for someone else (FR-MNT-011 reaches both).
 *
 * @param  array<string, mixed>  $overrides
 */
function maintenanceFor(User $technician, string $typeSlug = 'corrective', array $overrides = []): MaintenanceRecord
{
    return MaintenanceRecord::factory()->create([
        'technician_id' => $technician->id,
        'created_by' => $technician->id,
        'maintenance_type_id' => MaintenanceType::query()->where('slug', $typeSlug)->value('id'),
        ...$overrides,
    ]);
}

/**
 * Give a user an individual permission grant or deny (FR-USER-010), and flush
 * the resolver's cache so the next request sees it. The setup for the recurring
 * "a per-user grant must not open an Administrator-only surface" assertion.
 */
function givePermission(User $user, string $name, PermissionGrantType $type = PermissionGrantType::Grant): void
{
    DB::table('user_permissions')->insert([
        'user_id' => $user->id,
        'permission_id' => Permission::query()->where('name', $name)->value('id'),
        'grant_type' => $type->value,
    ]);
    app(PermissionResolver::class)->forget($user);
}

/** A seeded maintenance type by slug. */
function maintenanceType(string $slug): MaintenanceType
{
    return MaintenanceType::query()->where('slug', $slug)->firstOrFail();
}
