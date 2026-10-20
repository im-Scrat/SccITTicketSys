<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\FloorPlanAssetPosition;
use App\Models\Room;
use App\Models\RoomLayout;

/*
 * "My assets" placement confirmation (owner request 2026-10-20, SDD DD-115).
 *
 * A custodian confirms that assets assigned to them are physically in their
 * rooms — one at a time or all at once — and confirmed assets then appear on
 * that room's Floorplan. Authorization, role by role and record by record:
 *
 *   - teachers are refused (403), as for the whole custody surface;
 *   - another custodian's asset, or one nobody holds, is a 404 by uuid — a
 *     record absent from "My assets" is equally unreachable here (no IDOR);
 *   - "Confirm all" touches only the caller's own custody.
 */

beforeEach(function () {
    seedRbac();
    $this->technician = userWithRole('technician');
    $this->room = Room::factory()->create(['name' => 'Lab 2']);
    $this->mine = Asset::factory()->create([
        'assigned_technician_id' => $this->technician->id,
        'current_room_id' => $this->room->id,
    ]);
    $this->confirm = fn (Asset $asset): string => "/api/my/assets/{$asset->uuid}/confirm-placement";
});

/* ---- who may confirm ---------------------------------------------------- */

it('refuses a teacher, for one asset and for all', function () {
    $teacher = userWithRole('teacher');

    $this->actingAs($teacher)->postJson(($this->confirm)($this->mine))->assertForbidden();
    $this->actingAs($teacher)->postJson('/api/my/assets/confirm-placement')->assertForbidden();

    expect($this->mine->fresh()->placement_confirmed_at)->toBeNull();
});

it('answers 404 for another custodian’s asset, exactly as for one that does not exist', function () {
    $theirs = Asset::factory()->create([
        'assigned_technician_id' => userWithRole('technician')->id,
        'current_room_id' => $this->room->id,
    ]);
    $nobodys = Asset::factory()->create(['assigned_technician_id' => null, 'current_room_id' => $this->room->id]);

    $this->actingAs($this->technician)->postJson(($this->confirm)($theirs))->assertNotFound();
    $this->actingAs($this->technician)->postJson(($this->confirm)($nobodys))->assertNotFound();
    $this->actingAs($this->technician)->postJson('/api/my/assets/'.fake()->uuid().'/confirm-placement')->assertNotFound();

    expect($theirs->fresh()->placement_confirmed_at)->toBeNull()
        ->and($nobodys->fresh()->placement_confirmed_at)->toBeNull();
});

it('lets an administrator confirm what is in their own custody', function () {
    $admin = userWithRole('administrator');
    $held = Asset::factory()->create(['assigned_technician_id' => $admin->id, 'current_room_id' => $this->room->id]);

    $this->actingAs($admin)->postJson(($this->confirm)($held))->assertOk();

    // Being an administrator does not reach someone else's custody here.
    $this->actingAs($admin)->postJson(($this->confirm)($this->mine))->assertNotFound();
});

/* ---- what a confirmation does ------------------------------------------- */

it('confirms one asset, records who and when, and audits it on the asset', function () {
    $this->actingAs($this->technician)->postJson(($this->confirm)($this->mine))
        ->assertOk()
        ->assertJsonPath('data.id', $this->mine->uuid)
        ->assertJsonPath('data.placement_confirmed_at', fn ($at) => is_string($at));

    $fresh = $this->mine->fresh();
    expect($fresh->placement_confirmed_by)->toBe($this->technician->id)
        ->and(ActivityLog::query()
            ->where('action', ActivityAction::AssetPlacementConfirmed->value)
            ->where('subject_id', $this->mine->id)
            ->count())->toBe(1);
});

it('is idempotent: confirming again changes nothing and writes no second audit row', function () {
    $this->actingAs($this->technician)->postJson(($this->confirm)($this->mine))->assertOk();
    $first = $this->mine->fresh()->placement_confirmed_at;

    $this->travel(5)->minutes();
    $this->actingAs($this->technician)->postJson(($this->confirm)($this->mine))->assertOk();

    expect($this->mine->fresh()->placement_confirmed_at->equalTo($first))->toBeTrue()
        ->and(ActivityLog::query()->where('action', ActivityAction::AssetPlacementConfirmed->value)->count())->toBe(1);
});

it('refuses to confirm an asset that has no room', function () {
    $roomless = Asset::factory()->create(['assigned_technician_id' => $this->technician->id, 'current_room_id' => null]);

    $this->actingAs($this->technician)->postJson(($this->confirm)($roomless))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset']);

    expect($roomless->fresh()->placement_confirmed_at)->toBeNull();
});

it('confirms all of the caller’s placed assets and nothing of anyone else’s', function () {
    $second = Asset::factory()->create(['assigned_technician_id' => $this->technician->id, 'current_room_id' => $this->room->id]);
    $roomless = Asset::factory()->create(['assigned_technician_id' => $this->technician->id, 'current_room_id' => null]);
    $theirs = Asset::factory()->create([
        'assigned_technician_id' => userWithRole('technician')->id,
        'current_room_id' => $this->room->id,
    ]);

    $this->actingAs($this->technician)->postJson('/api/my/assets/confirm-placement')
        ->assertOk()
        ->assertJsonPath('meta.confirmed', 2)
        ->assertJsonPath('meta.without_room', 1);

    expect($this->mine->fresh()->placement_confirmed_at)->not->toBeNull()
        ->and($second->fresh()->placement_confirmed_at)->not->toBeNull()
        ->and($roomless->fresh()->placement_confirmed_at)->toBeNull()
        ->and($theirs->fresh()->placement_confirmed_at)->toBeNull();

    // Pressed again: nothing left to confirm.
    $this->actingAs($this->technician)->postJson('/api/my/assets/confirm-placement')
        ->assertOk()
        ->assertJsonPath('meta.confirmed', 0);
});

it('shows the confirmation in "My assets"', function () {
    $this->actingAs($this->technician)->postJson(($this->confirm)($this->mine))->assertOk();

    $this->actingAs($this->technician)->getJson('/api/my/assets')
        ->assertOk()
        ->assertJsonPath('data.0.placement_confirmed_at', fn ($at) => is_string($at));
});

/* ---- a move undoes it --------------------------------------------------- */

it('clears the confirmation and the Floorplan place when the asset moves to another room', function () {
    $admin = userWithRole('administrator');
    $layout = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);

    $this->actingAs($this->technician)->postJson(($this->confirm)($this->mine))->assertOk();
    FloorPlanAssetPosition::query()->create([
        'room_layout_id' => $layout->id, 'asset_id' => $this->mine->id, 'pos_x' => 100, 'pos_y' => 100, 'rotation' => 0,
    ]);

    $this->actingAs($admin)->postJson("/api/admin/assets/{$this->mine->uuid}/transfer", [
        'room' => Room::factory()->create()->uuid,
    ])->assertOk();

    $fresh = $this->mine->fresh();
    expect($fresh->placement_confirmed_at)->toBeNull()
        ->and($fresh->placement_confirmed_by)->toBeNull()
        ->and(FloorPlanAssetPosition::query()->where('asset_id', $this->mine->id)->exists())->toBeFalse();
});
