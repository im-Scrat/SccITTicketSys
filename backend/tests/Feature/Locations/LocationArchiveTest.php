<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;

beforeEach(fn () => seedRbac());

it('cascades an archive from the building down to its floors and rooms', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id]);

    $this->actingAs($admin)->deleteJson("/api/admin/buildings/{$building->uuid}")->assertOk();

    // Nothing is hard-deleted (BR-12) — every row is still there, archived.
    expect(Building::query()->whereKey($building->id)->exists())->toBeFalse()
        ->and(Building::withTrashed()->whereKey($building->id)->exists())->toBeTrue()
        ->and(Floor::query()->whereKey($floor->id)->exists())->toBeFalse()
        ->and(Floor::withTrashed()->whereKey($floor->id)->exists())->toBeTrue()
        ->and(Room::query()->whereKey($room->id)->exists())->toBeFalse()
        ->and(Room::withTrashed()->whereKey($room->id)->exists())->toBeTrue();
});

it('stamps the whole cascade with one shared deleted_at receipt', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id]);

    $this->actingAs($admin)->deleteJson("/api/admin/buildings/{$building->uuid}")->assertOk();

    $stamp = Building::withTrashed()->findOrFail($building->id)->deleted_at;

    expect($stamp)->not->toBeNull()
        ->and(Floor::withTrashed()->findOrFail($floor->id)->deleted_at->eq($stamp))->toBeTrue()
        ->and(Room::withTrashed()->findOrFail($room->id)->deleted_at->eq($stamp))->toBeTrue();
});

it('restores exactly the rows archived with the building', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id]);

    $this->actingAs($admin)->deleteJson("/api/admin/buildings/{$building->uuid}")->assertOk();
    $this->actingAs($admin)->postJson("/api/admin/buildings/{$building->uuid}/restore")->assertOk();

    expect(Building::query()->whereKey($building->id)->exists())->toBeTrue()
        ->and(Floor::query()->whereKey($floor->id)->exists())->toBeTrue()
        ->and(Room::query()->whereKey($room->id)->exists())->toBeTrue();
});

it('does not resurrect a room that was archived on its own before the building', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $archivedEarlier = Room::factory()->create(['floor_id' => $floor->id]);
    $archivedWithBuilding = Room::factory()->create(['floor_id' => $floor->id]);

    // The room is retired for its own reasons first…
    $this->actingAs($admin)->deleteJson("/api/admin/rooms/{$archivedEarlier->uuid}")->assertOk();

    // …then the whole building is archived in the *same second* — the cascade
    // stamp must still be distinguishable (SDD DD-27).
    $this->actingAs($admin)->deleteJson("/api/admin/buildings/{$building->uuid}")->assertOk();
    $this->actingAs($admin)->postJson("/api/admin/buildings/{$building->uuid}/restore")->assertOk();

    expect(Room::query()->whereKey($archivedWithBuilding->id)->exists())->toBeTrue()
        ->and(Room::query()->whereKey($archivedEarlier->id)->exists())->toBeFalse();
});

it('cascades a floor archive to its rooms and restores only those', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $otherFloor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id]);
    $untouched = Room::factory()->create(['floor_id' => $otherFloor->id]);

    $this->actingAs($admin)->deleteJson("/api/admin/floors/{$floor->uuid}")->assertOk();

    expect(Room::query()->whereKey($room->id)->exists())->toBeFalse()
        ->and(Room::query()->whereKey($untouched->id)->exists())->toBeTrue();

    $this->actingAs($admin)->postJson("/api/admin/floors/{$floor->uuid}/restore")->assertOk();

    expect(Room::query()->whereKey($room->id)->exists())->toBeTrue();
});

it('records the cascade counts on the audit trail', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    Room::factory()->count(2)->create(['floor_id' => $floor->id]);

    $this->actingAs($admin)->deleteJson("/api/admin/buildings/{$building->uuid}")->assertOk();

    $this->actingAs($admin)->getJson("/api/admin/buildings/{$building->uuid}/audit")
        ->assertOk()
        ->assertJsonPath('data.0.action', 'location_archived')
        ->assertJsonPath('data.0.properties.cascaded_floors', 1)
        ->assertJsonPath('data.0.properties.cascaded_rooms', 2);
});

it('hides an archived subtree from the directory and the picker', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    Room::factory()->create(['floor_id' => $floor->id]);

    $this->actingAs($admin)->getJson('/api/admin/rooms')->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($admin)->deleteJson("/api/admin/buildings/{$building->uuid}")->assertOk();

    $this->actingAs($admin)->getJson('/api/admin/rooms')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($admin)->getJson('/api/lookups/rooms')->assertOk()->assertJsonCount(0, 'data');

    // …but remains findable when explicitly asked for.
    $this->actingAs($admin)->getJson('/api/admin/rooms?trashed=only')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.archived', true);
});
