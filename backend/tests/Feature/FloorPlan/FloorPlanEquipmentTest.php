<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\FloorPlanAssetPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;

/*
 * Confirmed equipment on the Floorplan (owner request 2026-10-20, SDD DD-115).
 *
 * Once a custodian confirms an asset is in its room, the room's Floorplan
 * lists it as "not placed" and an administrator can arrange it with the PCs.
 * The guarantees mirror the PCs': nothing outside the room (or not confirmed
 * there) is ever drawn or accepted, placing never moves anything, the same
 * geometry rules apply, and a save that does not mention equipment keeps it.
 */

beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->room = Room::factory()->create(['name' => 'Lab 3']);
    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true,
    ]);
    $this->admin = userWithRole('administrator');

    $this->printer = Asset::factory()->create([
        'name' => 'Lab 3 printer',
        'current_room_id' => $this->room->id,
        'placement_confirmed_at' => now(),
    ]);
    $this->unconfirmed = Asset::factory()->create(['name' => 'Unseen projector', 'current_room_id' => $this->room->id]);
    $this->elsewhere = Asset::factory()->create([
        'name' => 'Other room UPS',
        'current_room_id' => Room::factory()->create()->id,
        'placement_confirmed_at' => now(),
    ]);

    $this->plan = fn () => $this->actingAs($this->admin)->getJson("/api/admin/floor-plan/rooms/{$this->room->uuid}");
    $this->save = fn (array $equipment, array $workstations = []) => $this->actingAs($this->admin)->putJson(
        "/api/admin/floor-plan/rooms/{$this->room->uuid}/layouts/1",
        [
            'expected_revision' => $this->layout->fresh()->revision,
            'settings' => ['width' => 1000, 'height' => 600, 'grid_size' => 20],
            'workstations' => $workstations,
            'fixtures' => [],
            'equipment' => $equipment,
        ],
    );
    $this->item = fn (Asset $asset, float $x, float $y, int $rotation = 0): array => [
        'asset' => $asset->uuid, 'x' => $x, 'y' => $y, 'rotation' => $rotation,
    ];
});

it('lists only confirmed equipment in this room as waiting for a place', function (): void {
    $names = collect(($this->plan)()->assertOk()->json('data.unplaced_equipment'))->pluck('name');

    expect($names->all())->toBe(['Lab 3 printer']);
});

it('shows confirmed equipment even before the room has a layout', function (): void {
    $bare = Room::factory()->create();
    Asset::factory()->create(['name' => 'Bare-room scanner', 'current_room_id' => $bare->id, 'placement_confirmed_at' => now()]);

    $this->actingAs($this->admin)->getJson("/api/admin/floor-plan/rooms/{$bare->uuid}")
        ->assertOk()
        ->assertJsonPath('data.unplaced_equipment.0.name', 'Bare-room scanner');
});

it('saves a place for confirmed equipment, audits it, and draws it there', function (): void {
    ($this->save)([($this->item)($this->printer, 300, 300, 90)])
        ->assertOk()
        ->assertJsonPath('data.layout.revision', 2)
        ->assertJsonPath('data.equipment.0.id', $this->printer->uuid)
        ->assertJsonPath('data.equipment.0.rotation', 90)
        ->assertJsonPath('data.unplaced_equipment', []);

    expect(FloorPlanAssetPosition::query()->where('asset_id', $this->printer->id)->value('pos_x'))->toEqual('300.00')
        ->and(ActivityLog::query()
            ->where('action', ActivityAction::AssetPositionChanged->value)
            ->where('subject_id', $this->printer->id)
            ->exists())->toBeTrue();
});

it('keeps the narrow map shape: no serial number, price or notes', function (): void {
    $this->printer->forceFill(['serial_number' => 'SN-SECRET-1', 'notes' => 'NOTE-SECRET-1'])->save();

    $json = ($this->plan)()->assertOk()->getContent();

    expect($json)->not->toContain('SN-SECRET-1')->and($json)->not->toContain('NOTE-SECRET-1');
});

it('refuses equipment that is not confirmed here or lives in another room, and writes nothing', function (Closure $pick): void {
    $asset = $pick->call($this);

    ($this->save)([($this->item)($asset, 300, 300)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['equipment.0.asset']);

    expect(FloorPlanAssetPosition::query()->count())->toBe(0)
        ->and($this->layout->fresh()->revision)->toBe(1);
})->with([
    'not confirmed' => [fn () => $this->unconfirmed],
    'another room' => [fn () => $this->elsewhere],
]);

it('refuses equipment outside the room or on top of a workstation', function (): void {
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id]);

    ($this->save)([($this->item)($this->printer, 990, 300)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['equipment.0.x']);

    ($this->save)([($this->item)($this->printer, 100, 100)], [['pc_unit' => $pc->uuid, 'x' => 100, 'y' => 100, 'rotation' => 0]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['equipment.0.x', 'workstations.0.x']);
});

it('keeps equipment where it is when a save does not mention it', function (): void {
    ($this->save)([($this->item)($this->printer, 300, 300)])->assertOk();

    $this->actingAs($this->admin)->putJson("/api/admin/floor-plan/rooms/{$this->room->uuid}/layouts/1", [
        'expected_revision' => $this->layout->fresh()->revision,
        'settings' => ['width' => 1000, 'height' => 600, 'grid_size' => 20],
        'workstations' => [],
        'fixtures' => [],
    ])->assertOk()->assertJsonPath('data.equipment.0.id', $this->printer->uuid);
});

it('takes equipment off the plan when a save leaves it out of the list', function (): void {
    ($this->save)([($this->item)($this->printer, 300, 300)])->assertOk();

    ($this->save)([])
        ->assertOk()
        ->assertJsonPath('data.equipment', [])
        ->assertJsonPath('data.unplaced_equipment.0.id', $this->printer->uuid);
});

it('refuses equipment to a non-administrator', function (): void {
    $this->actingAs(userWithRole('technician'))
        ->putJson("/api/admin/floor-plan/rooms/{$this->room->uuid}/layouts/1", [
            'expected_revision' => 1,
            'settings' => ['width' => 1000, 'height' => 600, 'grid_size' => 20],
            'workstations' => [],
            'fixtures' => [],
            'equipment' => [($this->item)($this->printer, 300, 300)],
        ])
        ->assertForbidden();

    $this->actingAs(userWithRole('technician'))
        ->getJson("/api/admin/floor-plan/rooms/{$this->room->uuid}")
        ->assertForbidden();

    expect(FloorPlanAssetPosition::query()->count())->toBe(0);
});

it('stops drawing equipment that has since left the room', function (): void {
    ($this->save)([($this->item)($this->printer, 300, 300)])->assertOk();

    // Moved out behind the plan's back (a raw update, not TransferAsset).
    $this->printer->forceFill(['current_room_id' => Room::factory()->create()->id])->save();

    $plan = ($this->plan)()->assertOk();
    expect($plan->json('data.equipment'))->toBe([])
        ->and($plan->json('data.unplaced_equipment'))->toBe([]);
});
