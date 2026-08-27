<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Permission;
use App\Models\Room;

beforeEach(fn () => seedRbac());

it('requires authentication for every location endpoint', function () {
    $room = Room::factory()->create();

    $this->getJson('/api/admin/rooms')->assertUnauthorized();
    $this->getJson('/api/lookups/rooms')->assertUnauthorized();
    $this->deleteJson("/api/admin/rooms/{$room->uuid}")->assertUnauthorized();
});

it('grants the locations module to Administrators only', function () {
    expect(userWithRole('administrator')->hasPermissionTo('locations.view'))->toBeTrue()
        ->and(userWithRole('technician')->hasPermissionTo('locations.view'))->toBeFalse()
        ->and(userWithRole('teacher')->hasPermissionTo('locations.view'))->toBeFalse();
});

it('closes the whole administrative module to technicians and teachers', function () {
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id]);

    foreach (['technician', 'teacher'] as $roleSlug) {
        $actor = userWithRole($roleSlug);

        // Dashboards, tree and directories.
        $this->actingAs($actor)->getJson('/api/admin/locations/dashboard')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/admin/locations/tree')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/admin/buildings')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/admin/rooms')->assertForbidden();

        // Detail pages at every level.
        $this->actingAs($actor)->getJson("/api/admin/buildings/{$building->uuid}")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/buildings/{$building->uuid}/floors")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/floors/{$floor->uuid}")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/rooms/{$room->uuid}")->assertForbidden();

        // Audit timelines.
        $this->actingAs($actor)->getJson("/api/admin/buildings/{$building->uuid}/audit")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/floors/{$floor->uuid}/audit")->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/rooms/{$room->uuid}/audit")->assertForbidden();
    }
});

it('denies every location write to technicians and teachers', function () {
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id]);

    foreach (['technician', 'teacher'] as $roleSlug) {
        $actor = userWithRole($roleSlug);

        $this->actingAs($actor)->postJson('/api/admin/buildings', [
            'name' => 'Nope', 'code' => 'NOPE-'.$roleSlug,
        ])->assertForbidden();

        $this->actingAs($actor)->putJson("/api/admin/buildings/{$building->uuid}", [
            'name' => 'Renamed', 'code' => $building->code,
        ])->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/buildings/{$building->uuid}/activate")->assertForbidden();
        $this->actingAs($actor)->postJson("/api/admin/buildings/{$building->uuid}/deactivate")->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/buildings/{$building->uuid}/floors", [
            'floor_number' => 9, 'name' => 'Nope',
        ])->assertForbidden();

        $this->actingAs($actor)->putJson("/api/admin/floors/{$floor->uuid}", [
            'floor_number' => 4, 'name' => 'Renamed',
        ])->assertForbidden();

        $this->actingAs($actor)->postJson('/api/admin/rooms', [
            'floor' => $floor->uuid, 'name' => 'Nope', 'code' => 'NR-'.$roleSlug, 'room_type' => 'office',
        ])->assertForbidden();

        $this->actingAs($actor)->putJson("/api/admin/rooms/{$room->uuid}", [
            'name' => 'Renamed', 'code' => $room->code, 'room_type' => $room->room_type->value,
        ])->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/rooms/{$room->uuid}/activate")->assertForbidden();
        $this->actingAs($actor)->postJson("/api/admin/rooms/{$room->uuid}/deactivate")->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/rooms/{$room->uuid}/reassign", [
            'to_room' => Room::factory()->create()->uuid,
        ])->assertForbidden();

        $this->actingAs($actor)->deleteJson("/api/admin/rooms/{$room->uuid}")->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/admin/floors/{$floor->uuid}")->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/admin/buildings/{$building->uuid}")->assertForbidden();

        $this->actingAs($actor)->postJson("/api/admin/rooms/{$room->uuid}/restore")->assertForbidden();
        $this->actingAs($actor)->postJson("/api/admin/floors/{$floor->uuid}/restore")->assertForbidden();
        $this->actingAs($actor)->postJson("/api/admin/buildings/{$building->uuid}/restore")->assertForbidden();
    }

    // Nothing was mutated by the denied attempts.
    expect($building->fresh()->deleted_at)->toBeNull()
        ->and($room->fresh()->deleted_at)->toBeNull()
        ->and($building->fresh()->is_active)->toBeTrue()
        ->and(Building::query()->where('code', 'NOPE-technician')->exists())->toBeFalse();
});

it('still lets a non-admin name a place through the narrow lookup', function () {
    $room = Room::factory()->create();

    // A teacher holds tickets.create, a technician holds maintenance/assets reads —
    // either is enough to resolve a location field (FR-LOC-011), and neither
    // grants the module.
    foreach (['administrator', 'technician', 'teacher'] as $roleSlug) {
        $this->actingAs(userWithRole($roleSlug))
            ->getJson('/api/lookups/rooms')
            ->assertOk()
            ->assertJsonPath('data.0.id', $room->uuid);
    }
});

it('refuses the lookup to an account with no workflow that needs a location', function () {
    $teacher = userWithRole('teacher');

    // Strip every consuming permission a teacher holds.
    foreach (['tickets.view', 'tickets.create'] as $name) {
        $permission = Permission::query()->where('name', $name)->firstOrFail();
        $teacher->directPermissions()->attach($permission->id, ['grant_type' => 'deny']);
    }

    $this->actingAs($teacher->fresh())->getJson('/api/lookups/rooms')->assertForbidden();
    $this->actingAs($teacher->fresh())->getJson('/api/lookups/buildings')->assertForbidden();
    $this->actingAs($teacher->fresh())->getJson('/api/lookups/floors')->assertForbidden();
});

it('honours a per-user grant that opens the module to a technician', function () {
    $technician = userWithRole('technician');

    $permission = Permission::query()->where('name', 'locations.view')->firstOrFail();
    $technician->directPermissions()->attach($permission->id, ['grant_type' => 'grant']);

    $this->actingAs($technician->fresh())->getJson('/api/admin/rooms')->assertOk();
});

it('grants location writes to a technician who is individually granted them', function () {
    $technician = userWithRole('technician');

    foreach (['locations.create', 'locations.delete'] as $name) {
        $permission = Permission::query()->where('name', $name)->firstOrFail();
        $technician->directPermissions()->attach($permission->id, ['grant_type' => 'grant']);
    }

    $this->actingAs($technician->fresh())->postJson('/api/admin/buildings', [
        'name' => 'Granted Hall', 'code' => 'GRANT-1',
    ])->assertCreated();
});

it('denies a suspended administrator', function () {
    $admin = userWithRole('administrator', ['status' => UserStatus::Suspended->value]);

    $this->actingAs($admin)->getJson('/api/admin/rooms')->assertForbidden();
    $this->actingAs($admin)->getJson('/api/lookups/rooms')->assertForbidden();
});
