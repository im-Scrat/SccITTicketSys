<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Policies\FloorPlanPositionPolicy;
use App\Domains\FloorPlan\Policies\RoomLayoutPolicy;
use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\Locations\Policies\RoomPolicy;
use App\Enums\PermissionGrantType;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * WP-B — the Interactive Floor Plan is Administrator-only.
 *
 * Asserted role by role, with the negative cases first-class, and asserted
 * against the *real* `Gate` (so `Gate::before`, policy resolution and the
 * seeded permission matrix all participate) rather than by calling a policy
 * method directly.
 */
beforeEach(fn () => seedRbac());

function roleBySlug(string $slug): Role
{
    return Role::query()->where('slug', $slug)->firstOrFail();
}

/** Give a user a permission that their role does not carry (FR-USER-010). */
function grantPermission(User $user, string $permission, PermissionGrantType $type = PermissionGrantType::Grant): void
{
    DB::table('user_permissions')->insert([
        'user_id' => $user->getKey(),
        'permission_id' => Permission::query()->where('name', $permission)->value('id'),
        'grant_type' => $type->value,
    ]);

    app(PermissionResolver::class)->forget($user);
}

/** @return array{layout: RoomLayout, position: FloorPlanPosition} */
function floorPlanSubjects(): array
{
    $room = Room::factory()->create();
    $layout = RoomLayout::factory()->create(['room_id' => $room->id]);
    $pc = PcUnit::factory()->create(['room_id' => $room->id]);
    $position = FloorPlanPosition::factory()->create(['room_layout_id' => $layout->id, 'pc_unit_id' => $pc->id]);

    return ['layout' => $layout, 'position' => $position];
}

// ── The four principals ─────────────────────────────────────────────────────

it('allows an Administrator every floor-plan ability', function (): void {
    ['layout' => $layout, 'position' => $position] = floorPlanSubjects();
    $gate = Gate::forUser(userWithRole('administrator'));

    expect($gate->allows('viewAny', RoomLayout::class))->toBeTrue()
        ->and($gate->allows('view', $layout))->toBeTrue()
        ->and($gate->allows('manage', RoomLayout::class))->toBeTrue()
        ->and($gate->allows('manage', $layout))->toBeTrue()
        ->and($gate->allows('viewAny', FloorPlanPosition::class))->toBeTrue()
        ->and($gate->allows('view', $position))->toBeTrue()
        ->and($gate->allows('manage', FloorPlanPosition::class))->toBeTrue()
        ->and($gate->allows('manage', $position))->toBeTrue();
});

it('denies a Technician every floor-plan ability', function (): void {
    ['layout' => $layout, 'position' => $position] = floorPlanSubjects();
    $gate = Gate::forUser(userWithRole('technician'));

    expect($gate->denies('viewAny', RoomLayout::class))->toBeTrue()
        ->and($gate->denies('view', $layout))->toBeTrue()
        ->and($gate->denies('manage', RoomLayout::class))->toBeTrue()
        ->and($gate->denies('manage', $layout))->toBeTrue()
        ->and($gate->denies('viewAny', FloorPlanPosition::class))->toBeTrue()
        ->and($gate->denies('view', $position))->toBeTrue()
        ->and($gate->denies('manage', FloorPlanPosition::class))->toBeTrue()
        ->and($gate->denies('manage', $position))->toBeTrue();
});

it('denies a Teacher every floor-plan ability', function (): void {
    ['layout' => $layout, 'position' => $position] = floorPlanSubjects();
    $gate = Gate::forUser(userWithRole('teacher'));

    expect($gate->denies('viewAny', RoomLayout::class))->toBeTrue()
        ->and($gate->denies('view', $layout))->toBeTrue()
        ->and($gate->denies('manage', RoomLayout::class))->toBeTrue()
        ->and($gate->denies('manage', $layout))->toBeTrue()
        ->and($gate->denies('viewAny', FloorPlanPosition::class))->toBeTrue()
        ->and($gate->denies('view', $position))->toBeTrue()
        ->and($gate->denies('manage', FloorPlanPosition::class))->toBeTrue()
        ->and($gate->denies('manage', $position))->toBeTrue();
});

it('denies an unauthenticated caller every floor-plan ability', function (): void {
    ['layout' => $layout, 'position' => $position] = floorPlanSubjects();

    expect(Auth::check())->toBeFalse()
        ->and(Gate::allows('viewAny', RoomLayout::class))->toBeFalse()
        ->and(Gate::allows('view', $layout))->toBeFalse()
        ->and(Gate::allows('manage', $layout))->toBeFalse()
        ->and(Gate::allows('view', $position))->toBeFalse()
        ->and(Gate::allows('manage', $position))->toBeFalse();
});

it('throws an AuthorizationException, not a silent empty result, on a denied authorize()', function (): void {
    ['layout' => $layout] = floorPlanSubjects();

    Gate::forUser(userWithRole('technician'))->authorize('view', $layout);
})->throws(AuthorizationException::class);

// ── Permission matrix ───────────────────────────────────────────────────────

it('withdraws floorplan.view from the Technician baseline', function (): void {
    expect(userWithRole('technician')->hasPermissionTo('floorplan.view'))->toBeFalse()
        ->and(userWithRole('technician')->hasPermissionTo('floorplan.manage'))->toBeFalse()
        ->and(userWithRole('teacher')->hasPermissionTo('floorplan.view'))->toBeFalse()
        ->and(userWithRole('administrator')->hasPermissionTo('floorplan.view'))->toBeTrue()
        ->and(userWithRole('administrator')->hasPermissionTo('floorplan.manage'))->toBeTrue();
});

it('revokes floorplan.view from a database that was seeded before the withdrawal', function (): void {
    // The mechanism under test is PermissionSeeder::$withdrawn, because that is
    // what converges an *existing* database — the baseline list alone only
    // stops new grants. Re-create the pre-2.8 state, then re-seed.
    $technician = roleBySlug('technician');
    $technician->permissions()->attach(Permission::query()->where('name', 'floorplan.view')->value('id'));
    $user = User::factory()->create(['role_id' => $technician->id]);
    app(PermissionResolver::class)->forget($user);

    expect($user->hasPermissionTo('floorplan.view'))->toBeTrue();

    test()->seed(PermissionSeeder::class);
    app(PermissionResolver::class)->forget($user);

    // A fresh instance, as the next request would have: `$user` still carries
    // the role relation it loaded above, and the resolver reads that.
    expect($user->fresh()?->hasPermissionTo('floorplan.view'))->toBeFalse();
});

it('leaves every other Technician permission exactly as it was', function (): void {
    $expected = [
        'ai.feedback', 'ai.view',
        'inventory.adjust', 'inventory.view',
        'knowledge.create', 'knowledge.view',
        'maintenance.complete', 'maintenance.create', 'maintenance.delete',
        'maintenance.update', 'maintenance.view',
        'reports.view',
        'tickets.comment', 'tickets.update', 'tickets.view',
    ];

    $actual = roleBySlug('technician')->permissions()->pluck('name')->sort()->values()->all();

    expect($actual)->toBe($expected);
});

it('leaves the Teacher permission set untouched', function (): void {
    $actual = roleBySlug('teacher')->permissions()->pluck('name')->sort()->values()->all();

    expect($actual)->toBe([
        'ai.feedback', 'ai.view', 'knowledge.view',
        'tickets.comment', 'tickets.create', 'tickets.view', 'tickets.vote',
    ]);
});

it('keeps the existing lookup abilities working without floorplan.view', function (): void {
    // `floorplan.view` was one of several permissions that open the narrow
    // room/PC lookups. Withdrawing it must not close them for a Technician —
    // they still hold tickets.* and maintenance.*.
    $technician = userWithRole('technician');

    expect(Gate::forUser($technician)->allows('selectLocation', Room::class))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('selectPcUnit', PcUnit::class))->toBeTrue();
});

// ── Policy registration and resolution ──────────────────────────────────────

it('registers exactly the two floor-plan policies against their models', function (): void {
    expect(Gate::getPolicyFor(RoomLayout::class))->toBeInstanceOf(RoomLayoutPolicy::class)
        ->and(Gate::getPolicyFor(FloorPlanPosition::class))->toBeInstanceOf(FloorPlanPositionPolicy::class)
        ->and(Gate::policies())->toHaveKey(RoomLayout::class, RoomLayoutPolicy::class)
        ->and(Gate::policies())->toHaveKey(FloorPlanPosition::class, FloorPlanPositionPolicy::class);
});

it('does not disturb the policies registered before it', function (): void {
    expect(Gate::getPolicyFor(Room::class))->toBeInstanceOf(RoomPolicy::class);
});

it('exposes exactly the viewAny, view and manage abilities', function (): void {
    foreach ([RoomLayoutPolicy::class, FloorPlanPositionPolicy::class] as $policy) {
        $abilities = collect((new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->reject(fn (ReflectionMethod $m): bool => $m->isConstructor())
            ->map(fn (ReflectionMethod $m): string => $m->getName())
            ->sort()->values()->all();

        expect($abilities)->toBe(['manage', 'view', 'viewAny']);
    }
});

// ── Gate::before ────────────────────────────────────────────────────────────

it('runs the policy, and lets it deny, even when Gate::before is consulted first', function (): void {
    // Prove the policy — not Gate::before — made the decision. An Administrator
    // holds the *permission string* `floorplan.manage`; if Gate::before were
    // answering, `manage` would be unaffected by the policy. Withdrawing the
    // permission with a per-user deny flips the policy's answer while the role
    // stays Administrator: only an executing policy can do that.
    $admin = userWithRole('administrator');
    grantPermission($admin, 'floorplan.manage', PermissionGrantType::Deny);

    expect(Gate::forUser($admin)->allows('manage', RoomLayout::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewAny', RoomLayout::class))->toBeTrue();
});

it('shows why a bare permission-string gate is not a safe boundary', function (): void {
    // Characterization of the existing global behaviour, kept here so nobody
    // "simplifies" a future route to `can:floorplan.manage`. A per-user grant
    // puts the permission in the Technician's effective set, and Gate::before
    // answers a permission-named ability with `true` before any policy runs.
    $technician = userWithRole('technician');
    grantPermission($technician, 'floorplan.manage');
    grantPermission($technician, 'floorplan.view');

    // The string gate fails open …
    expect(Gate::forUser($technician)->allows('floorplan.manage'))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('floorplan.view'))->toBeTrue();

    // … and the policy abilities do not.
    ['layout' => $layout, 'position' => $position] = floorPlanSubjects();
    $gate = Gate::forUser($technician);

    expect($gate->denies('viewAny', RoomLayout::class))->toBeTrue()
        ->and($gate->denies('view', $layout))->toBeTrue()
        ->and($gate->denies('manage', $layout))->toBeTrue()
        ->and($gate->denies('manage', RoomLayout::class))->toBeTrue()
        ->and($gate->denies('view', $position))->toBeTrue()
        ->and($gate->denies('manage', $position))->toBeTrue();
});

it('is not reopened for a Teacher by a per-user grant either', function (): void {
    $teacher = userWithRole('teacher');
    grantPermission($teacher, 'floorplan.manage');
    grantPermission($teacher, 'floorplan.view');
    ['layout' => $layout] = floorPlanSubjects();

    expect(Gate::forUser($teacher)->denies('view', $layout))->toBeTrue()
        ->and(Gate::forUser($teacher)->denies('manage', $layout))->toBeTrue();
});

it('requires the permission as well as the role', function (): void {
    // An Administrator with a per-user deny on floorplan.view loses the view
    // ability but keeps manage — the two are independent permissions.
    $admin = userWithRole('administrator');
    grantPermission($admin, 'floorplan.view', PermissionGrantType::Deny);
    ['layout' => $layout] = floorPlanSubjects();

    expect(Gate::forUser($admin)->denies('view', $layout))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('manage', $layout))->toBeTrue();
});

it('leaves Gate::before untouched for every other module', function (): void {
    // The correction for this package is confined to the floor-plan policies.
    // Global behaviour: a permission-named ability is still granted by the
    // permission, and an unheld one still falls through to deny.
    $technician = userWithRole('technician');

    expect(Gate::forUser($technician)->allows('tickets.view'))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('locations.view'))->toBeFalse()
        ->and(Gate::forUser($technician)->allows('nonexistent.permission'))->toBeFalse();
});
