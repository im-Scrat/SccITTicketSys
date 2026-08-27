<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;

beforeEach(fn () => seedRbac());

it('searches buildings by name, code and address', function () {
    $admin = userWithRole('administrator');
    Building::factory()->create(['name' => 'Science Hall', 'code' => 'SCI', 'address' => 'Fields Road']);
    Building::factory()->create(['name' => 'Arts Annex', 'code' => 'ART', 'address' => 'Kiln Lane']);

    $this->actingAs($admin)->getJson('/api/admin/buildings?search=science')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'SCI');

    $this->actingAs($admin)->getJson('/api/admin/buildings?search=kiln')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ART');
});

it('treats wildcards in a search term literally', function () {
    $admin = userWithRole('administrator');
    Building::factory()->create(['name' => '100% Cotton Hall', 'code' => 'PCT']);
    Building::factory()->create(['name' => 'Ordinary Hall', 'code' => 'ORD']);

    // A bare `%` would match everything if it were not escaped.
    $this->actingAs($admin)->getJson('/api/admin/buildings?search=%25')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'PCT');
});

it('filters rooms by building, floor, type and active state', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floorOne = Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 1]);
    $floorTwo = Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 2]);
    $elsewhere = Floor::factory()->create();

    Room::factory()->create(['floor_id' => $floorOne->id, 'room_type' => 'laboratory', 'is_active' => true]);
    Room::factory()->create(['floor_id' => $floorTwo->id, 'room_type' => 'office', 'is_active' => false]);
    Room::factory()->create(['floor_id' => $elsewhere->id, 'room_type' => 'laboratory', 'is_active' => true]);

    $this->actingAs($admin)->getJson("/api/admin/rooms?building={$building->uuid}")
        ->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($admin)->getJson("/api/admin/rooms?floor={$floorOne->uuid}")
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($admin)->getJson('/api/admin/rooms?room_type=laboratory')
        ->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($admin)->getJson('/api/admin/rooms?active=inactive')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('sorts rooms through the allow-list and rejects anything outside it', function () {
    $admin = userWithRole('administrator');
    Room::factory()->create(['name' => 'Zulu', 'capacity' => 10]);
    Room::factory()->create(['name' => 'Alpha', 'capacity' => 40]);

    $this->actingAs($admin)->getJson('/api/admin/rooms?sort=name&direction=asc')
        ->assertOk()->assertJsonPath('data.0.name', 'Alpha');

    $this->actingAs($admin)->getJson('/api/admin/rooms?sort=capacity&direction=desc')
        ->assertOk()->assertJsonPath('data.0.capacity', 40);

    // An out-of-range sort is a validation error, not a silently ignored value.
    $this->actingAs($admin)->getJson('/api/admin/rooms?sort=capacity);drop table rooms;--')
        ->assertStatus(422)->assertJsonValidationErrors(['sort']);
});

it('sorts rooms by their parent building and floor number', function () {
    $admin = userWithRole('administrator');
    $alpha = Building::factory()->create(['name' => 'Alpha Building']);
    $zulu = Building::factory()->create(['name' => 'Zulu Building']);
    Room::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $zulu->id, 'floor_number' => 1])->id]);
    Room::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $alpha->id, 'floor_number' => 5])->id]);

    $this->actingAs($admin)->getJson('/api/admin/rooms?sort=building&direction=asc')
        ->assertOk()->assertJsonPath('data.0.building.name', 'Alpha Building');

    $this->actingAs($admin)->getJson('/api/admin/rooms?sort=floor_number&direction=desc')
        ->assertOk()->assertJsonPath('data.0.floor.floor_number', 5);
});

it('paginates and caps the page size', function () {
    $admin = userWithRole('administrator');
    Room::factory()->count(7)->create();

    $this->actingAs($admin)->getJson('/api/admin/rooms?per_page=5')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs($admin)->getJson('/api/admin/rooms?per_page=5000')
        ->assertStatus(422)->assertJsonValidationErrors(['per_page']);
});

it('counts the live PC units in each room', function () {
    $admin = userWithRole('administrator');
    $room = Room::factory()->create();
    PcUnit::factory()->count(3)->create(['room_id' => $room->id]);

    $this->actingAs($admin)->getJson('/api/admin/rooms')
        ->assertOk()->assertJsonPath('data.0.pc_units_count', 3);
});

it('returns the location tree with per-level counts', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create(['name' => 'Main']);
    $floor = Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 1]);
    Room::factory()->count(2)->create(['floor_id' => $floor->id]);

    $this->actingAs($admin)->getJson('/api/admin/locations/tree')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Main')
        ->assertJsonPath('data.0.floors_count', 1)
        ->assertJsonPath('data.0.rooms_count', 2)
        ->assertJsonPath('data.0.floors.0.rooms_count', 2);
});

it('summarises the estate on the locations dashboard', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create(['is_active' => true]);
    Building::factory()->create(['is_active' => false]);
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    Room::factory()->create(['floor_id' => $floor->id, 'room_type' => 'laboratory', 'capacity' => 25]);
    PcUnit::factory()->create(['room_id' => null]);

    $response = $this->actingAs($admin)->getJson('/api/admin/locations/dashboard')->assertOk();

    expect($response->json('data.summary.buildings'))->toBe(2)
        ->and($response->json('data.summary.buildings_active'))->toBe(1)
        ->and($response->json('data.summary.buildings_inactive'))->toBe(1)
        ->and($response->json('data.summary.rooms'))->toBe(1)
        ->and($response->json('data.summary.total_capacity'))->toBe(25)
        ->and($response->json('data.occupancy.pc_units_unplaced'))->toBe(1)
        ->and($response->json('data.occupancy.empty_rooms'))->toBe(1);

    $laboratory = collect($response->json('data.by_room_type'))->firstWhere('value', 'laboratory');
    expect($laboratory['count'])->toBe(1)->and($laboratory['label'])->toBe('Laboratory');
});
