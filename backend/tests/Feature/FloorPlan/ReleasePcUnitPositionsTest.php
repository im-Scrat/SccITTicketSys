<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Events\RoomPlanChanged;
use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use Illuminate\Support\Facades\Event;

/**
 * Phase 2.11 — what happens to a machine's Floorplan place when the machine
 * leaves the room (`ReleasePcUnitPositions`, SDD DD-104): moved to another
 * room, moved in bulk with the room's occupants, or archived. The position on
 * the old room's *active* layout is released, audited on the machine, and the
 * layout's revision advances; positions on older versions stay as history;
 * both rooms' editors are told after commit.
 */
beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->admin = userWithRole('administrator');
    $this->from = Room::factory()->create(['name' => 'Lab 1']);
    $this->to = Room::factory()->create(['name' => 'Lab 2']);
    $this->layout = RoomLayout::factory()->create(['room_id' => $this->from->id, 'version' => 2, 'is_active' => true]);
    $this->history = RoomLayout::factory()->create(['room_id' => $this->from->id, 'version' => 1, 'is_active' => false]);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->from->id, 'pc_name' => 'PC-01']);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->pc->id, 'pos_x' => 100, 'pos_y' => 100]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->history->id, 'pc_unit_id' => $this->pc->id, 'pos_x' => 60, 'pos_y' => 60]);
});

it('releases the place on the old room\'s active layout when a machine moves rooms', function (): void {
    $this->actingAs($this->admin)->putJson("/api/admin/pc-units/{$this->pc->uuid}", ['room' => $this->to->uuid])->assertOk();

    expect(FloorPlanPosition::query()->where('room_layout_id', $this->layout->id)->exists())->toBeFalse()
        ->and($this->layout->fresh()->revision)->toBe(2);
});

it('keeps the place on an older version — that is history', function (): void {
    $this->actingAs($this->admin)->putJson("/api/admin/pc-units/{$this->pc->uuid}", ['room' => $this->to->uuid])->assertOk();

    expect(FloorPlanPosition::query()->where('room_layout_id', $this->history->id)->exists())->toBeTrue();
});

it('records where the machine stood on its own history', function (): void {
    $this->actingAs($this->admin)->putJson("/api/admin/pc-units/{$this->pc->uuid}", ['room' => $this->to->uuid])->assertOk();

    $log = ActivityLog::query()->where('action', ActivityAction::AssetPositionCleared->value)->where('subject_id', $this->pc->id)->sole();

    expect($log->properties['from'])->toEqual(['x' => 100, 'y' => 100, 'rotation' => 0])
        ->and($log->properties['reason'])->toBe('room_change')
        ->and($log->properties['room'])->toBe('Lab 1');
});

it('tells both rooms\' editors, by name, after the move commits', function (): void {
    Event::fake([RoomPlanChanged::class]);

    $this->actingAs($this->admin)->putJson("/api/admin/pc-units/{$this->pc->uuid}", ['room' => $this->to->uuid])->assertOk();

    Event::assertDispatched(RoomPlanChanged::class, fn (RoomPlanChanged $e) => $e->roomUuid === $this->from->uuid
        && $e->cause === 'roster' && $e->revision === 2 && $e->by === $this->admin->fullName());
    Event::assertDispatched(RoomPlanChanged::class, fn (RoomPlanChanged $e) => $e->roomUuid === $this->to->uuid
        && $e->cause === 'roster' && $e->revision === null);
});

it('does nothing to the Floorplan when an unrelated field changes', function (): void {
    Event::fake([RoomPlanChanged::class]);

    $this->actingAs($this->admin)->putJson("/api/admin/pc-units/{$this->pc->uuid}", ['notes' => 'Dusted'])->assertOk();

    expect(FloorPlanPosition::query()->where('room_layout_id', $this->layout->id)->exists())->toBeTrue();
    Event::assertNotDispatched(RoomPlanChanged::class);
});

it('releases the place when the machine is archived, and it returns unplaced when restored', function (): void {
    $this->actingAs($this->admin)->deleteJson("/api/admin/pc-units/{$this->pc->uuid}")->assertOk();

    expect(FloorPlanPosition::query()->where('room_layout_id', $this->layout->id)->exists())->toBeFalse();

    $this->actingAs($this->admin)->postJson("/api/admin/pc-units/{$this->pc->uuid}/restore")->assertOk();

    $this->actingAs($this->admin)
        ->getJson("/api/admin/floor-plan/rooms/{$this->from->uuid}")
        ->assertOk()
        ->assertJsonPath('data.pcs', [])
        ->assertJsonPath('data.unplaced.0.id', $this->pc->uuid);
});

it('releases every place when a room\'s occupants are moved together', function (): void {
    $second = PcUnit::factory()->create(['room_id' => $this->from->id]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $second->id, 'pos_x' => 200, 'pos_y' => 100]);

    $this->actingAs($this->admin)->postJson("/api/admin/rooms/{$this->from->uuid}/reassign", ['to_room' => $this->to->uuid])->assertOk();

    expect(FloorPlanPosition::query()->where('room_layout_id', $this->layout->id)->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', ActivityAction::AssetPositionCleared->value)->count())->toBe(2)
        ->and($this->layout->fresh()->revision)->toBe(2);
});

it('leaves a machine with no place alone, with no revision change', function (): void {
    $unplaced = PcUnit::factory()->create(['room_id' => $this->from->id]);

    $this->actingAs($this->admin)->putJson("/api/admin/pc-units/{$unplaced->uuid}", ['room' => $this->to->uuid])->assertOk();

    expect($this->layout->fresh()->revision)->toBe(1);
});
