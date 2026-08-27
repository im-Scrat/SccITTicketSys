<?php

declare(strict_types=1);

use App\Models\Building;

beforeEach(fn () => seedRbac());

it('creates a building with blame columns and an uppercased code', function () {
    $admin = userWithRole('administrator');

    $response = $this->actingAs($admin)->postJson('/api/admin/buildings', [
        'name' => 'Science Hall',
        'code' => 'sci-1',
        'address' => '14 Fields Road',
    ])->assertCreated();

    $response->assertJsonPath('data.name', 'Science Hall')
        ->assertJsonPath('data.code', 'SCI-1')
        ->assertJsonPath('data.is_active', true);

    $building = Building::query()->where('code', 'SCI-1')->firstOrFail();

    expect($building->created_by)->toBe($admin->id)
        ->and($building->updated_by)->toBe($admin->id);
});

it('addresses buildings by uuid and never exposes the numeric id', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();

    $this->actingAs($admin)->getJson("/api/admin/buildings/{$building->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $building->uuid)
        ->assertJsonMissingPath('data.building_id');

    // The numeric key is not a valid route key.
    $this->actingAs($admin)->getJson("/api/admin/buildings/{$building->id}")->assertNotFound();
});

it('rejects a duplicate code', function () {
    $admin = userWithRole('administrator');
    Building::factory()->create(['code' => 'MAIN']);

    $this->actingAs($admin)->postJson('/api/admin/buildings', [
        'name' => 'Another', 'code' => 'MAIN',
    ])->assertStatus(422)->assertJsonValidationErrors(['code']);
});

it('updates a building and stamps updated_by', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create(['name' => 'Old name']);

    $this->actingAs($admin)->putJson("/api/admin/buildings/{$building->uuid}", [
        'name' => 'New name',
        'code' => $building->code,
    ])->assertOk()->assertJsonPath('data.name', 'New name');

    expect($building->fresh()->updated_by)->toBe($admin->id);
});

it('deactivates and reactivates a building without archiving it', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create(['is_active' => true]);

    $this->actingAs($admin)->postJson("/api/admin/buildings/{$building->uuid}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($building->fresh()->deleted_at)->toBeNull();

    $this->actingAs($admin)->postJson("/api/admin/buildings/{$building->uuid}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('reports floor and room counts on the directory row', function () {
    $admin = userWithRole('administrator');
    $building = Building::factory()->create();
    $floor = $building->floors()->create(['floor_number' => 1, 'name' => 'Ground']);
    $floor->rooms()->create([
        'name' => 'Lab A', 'code' => 'LAB-A', 'room_type' => 'laboratory', 'is_active' => true,
    ]);

    $this->actingAs($admin)->getJson('/api/admin/buildings')
        ->assertOk()
        ->assertJsonPath('data.0.floors_count', 1)
        ->assertJsonPath('data.0.rooms_count', 1);
});
