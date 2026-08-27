<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;

beforeEach(fn () => seedRbac());

it('offers only selectable rooms and labels them with their full path', function () {
    $teacher = userWithRole('teacher');
    $building = Building::factory()->create(['name' => 'Science Hall', 'is_active' => true]);
    $floor = Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 2, 'name' => 'Second floor']);
    Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Lab 4', 'is_active' => true]);

    $this->actingAs($teacher)->getJson('/api/lookups/rooms')
        ->assertOk()
        ->assertJsonPath('data.0.label', 'Science Hall · Second floor · Lab 4')
        ->assertJsonPath('data.0.building.name', 'Science Hall')
        ->assertJsonPath('data.0.floor.floor_number', 2);
});

it('excludes a room that is itself inactive', function () {
    $teacher = userWithRole('teacher');
    Room::factory()->create(['is_active' => false]);

    $this->actingAs($teacher)->getJson('/api/lookups/rooms')->assertOk()->assertJsonCount(0, 'data');
});

it('excludes every room of an inactive building', function () {
    $teacher = userWithRole('teacher');
    $building = Building::factory()->create(['is_active' => false]);
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    Room::factory()->create(['floor_id' => $floor->id, 'is_active' => true]);

    $this->actingAs($teacher)->getJson('/api/lookups/rooms')->assertOk()->assertJsonCount(0, 'data');
});

it('excludes rooms under an archived floor', function () {
    $teacher = userWithRole('teacher');
    $admin = userWithRole('administrator');
    $floor = Floor::factory()->create();
    Room::factory()->create(['floor_id' => $floor->id, 'is_active' => true]);

    $this->actingAs($admin)->deleteJson("/api/admin/floors/{$floor->uuid}")->assertOk();

    $this->actingAs($teacher)->getJson('/api/lookups/rooms')->assertOk()->assertJsonCount(0, 'data');
});

it('never exposes operational detail through the picker', function () {
    $teacher = userWithRole('teacher');
    Room::factory()->create(['is_active' => true]);

    $response = $this->actingAs($teacher)->getJson('/api/lookups/rooms')->assertOk();
    $row = $response->json('data.0');

    expect($row)->toHaveKeys(['id', 'name', 'code', 'room_type', 'room_type_label', 'floor', 'building', 'label'])
        ->and($row)->not->toHaveKey('pc_units_count')
        ->and($row)->not->toHaveKey('created_by')
        ->and($row)->not->toHaveKey('capacity')
        ->and($row)->not->toHaveKey('archived');
});

it('searches and scopes the picker, and caps the result size', function () {
    $teacher = userWithRole('teacher');
    $building = Building::factory()->create(['name' => 'Alpha Hall']);
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Physics Lab', 'is_active' => true]);
    Room::factory()->create(['name' => 'Staff Office', 'is_active' => true]);

    $this->actingAs($teacher)->getJson('/api/lookups/rooms?search=physics')
        ->assertOk()->assertJsonCount(1, 'data');

    // A building name matches too — reporters think in buildings.
    $this->actingAs($teacher)->getJson('/api/lookups/rooms?search=alpha')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($teacher)->getJson("/api/lookups/rooms?building={$building->uuid}")
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($teacher)->getJson('/api/lookups/rooms?limit=1')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($teacher)->getJson('/api/lookups/rooms?limit=9999')
        ->assertStatus(422)->assertJsonValidationErrors(['limit']);
});

it('offers cascading building and floor lookups', function () {
    $teacher = userWithRole('teacher');
    $building = Building::factory()->create(['name' => 'Main', 'is_active' => true]);
    Floor::factory()->create(['building_id' => $building->id, 'floor_number' => 1]);
    Building::factory()->create(['is_active' => false]);

    $this->actingAs($teacher)->getJson('/api/lookups/buildings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Main');

    $this->actingAs($teacher)->getJson("/api/lookups/floors?building={$building->uuid}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.building.name', 'Main');
});
