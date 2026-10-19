<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seedRbac());

it('creates a floor addressable by its own uuid', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();

    $response = $this->actingAs($admin)->postJson("/api/admin/buildings/{$building->uuid}/floors", [
        'floor_number' => 2,
        'name' => 'Second floor',
    ])->assertCreated();

    $uuid = $response->json('data.id');

    expect($uuid)->toBeString()->not->toBeEmpty();

    $this->actingAs($admin)->getJson("/api/admin/floors/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.floor_number', 2)
        ->assertJsonPath('data.building.id', $building->uuid);
});

it('rejects a duplicate floor number in the same building but allows it in another', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $other = Building::factory()->create();
    Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 1]);

    $this->actingAs($admin)->postJson("/api/admin/buildings/{$building->uuid}/floors", [
        'floor_number' => 1, 'name' => 'Clash',
    ])->assertStatus(422)->assertJsonValidationErrors(['floor_number']);

    $this->actingAs($admin)->postJson("/api/admin/buildings/{$other->uuid}/floors", [
        'floor_number' => 1, 'name' => 'Fine',
    ])->assertCreated();
});

it('enforces the floor-number uniqueness at the database as well', function () {
    $building = Building::factory()->create();
    Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 3]);

    expect(fn () => DB::table('floors')->insert([
        'building_id' => $building->id,
        'floor_number' => 3,
        'name' => 'Bypass',
        'uuid' => (string) Str::uuid(),
    ]))->toThrow(QueryException::class);
});

it('allows a basement as a negative floor number', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();

    $this->actingAs($admin)->postJson("/api/admin/buildings/{$building->uuid}/floors", [
        'floor_number' => -1, 'name' => 'Basement',
    ])->assertCreated()->assertJsonPath('data.floor_number', -1);
});

it('creates a room on a floor with a typed room type and capacity', function () {
    $admin = userWithRole('administrator');
    $floor = Floor::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/admin/rooms', [
        'floor' => $floor->uuid,
        'name' => 'Computer Lab 1',
        'code' => 'lab-1',
        'room_type' => 'laboratory',
        'capacity' => 30,
    ])->assertCreated();

    $response->assertJsonPath('data.code', 'LAB-1')
        ->assertJsonPath('data.room_type', 'laboratory')
        ->assertJsonPath('data.room_type_label', 'Laboratory')
        ->assertJsonPath('data.capacity', 30)
        ->assertJsonPath('data.selectable', true);
});

it('rejects an unknown room type and a negative capacity', function () {
    $admin = userWithRole('administrator');
    $floor = Floor::factory()->create();

    $this->actingAs($admin)->postJson('/api/admin/rooms', [
        'floor' => $floor->uuid,
        'name' => 'Bad', 'code' => 'BAD-1',
        'room_type' => 'dungeon',
        'capacity' => -5,
    ])->assertStatus(422)->assertJsonValidationErrors(['room_type', 'capacity']);
});

it('enforces the non-negative capacity at the database as well', function () {
    $room = Room::factory()->create();

    expect(fn () => DB::table('rooms')->where('id', $room->id)->update(['capacity' => -1]))
        ->toThrow(QueryException::class);
});

it('moves a room to another floor and records the move in the audit trail', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $from = Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 1]);
    $to = Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 2]);
    $room = Room::factory()->create(['floor_id' => $from->id]);

    $this->actingAs($admin)->putJson("/api/admin/rooms/{$room->uuid}", [
        'floor' => $to->uuid,
        'name' => $room->name,
        'code' => $room->code,
        'room_type' => $room->room_type->value,
    ])->assertOk()->assertJsonPath('data.floor.id', $to->uuid);

    expect($room->fresh()->floor_id)->toBe($to->id);

    $this->actingAs($admin)->getJson("/api/admin/rooms/{$room->uuid}/audit")
        ->assertOk()
        ->assertJsonPath('data.0.action', 'location_updated')
        ->assertJsonPath('data.0.module', 'locations');
});

it('rejects a room whose target floor does not exist', function () {
    $admin = userWithRole('administrator');

    $this->actingAs($admin)->postJson('/api/admin/rooms', [
        'floor' => (string) Str::uuid(),
        'name' => 'Orphan', 'code' => 'ORP-1', 'room_type' => 'office',
    ])->assertStatus(422)->assertJsonValidationErrors(['floor']);
});
