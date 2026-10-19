<?php

declare(strict_types=1);

use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2.11 — the database's own rules for the Floorplan editor's tables
 * (migration 2026_10_19_110000, SDD DD-101/DD-102). Raw inserts, so each rule
 * is proven at the database rather than only in the application; one rejection
 * per test (a refused statement aborts the test transaction), asserted by
 * constraint name.
 */
beforeEach(function (): void {
    $this->layout = RoomLayout::factory()->create(['room_id' => Room::factory()->create()->id]);
    $this->fixture = fn (array $overrides = []): array => [
        'uuid' => (string) Str::uuid(), 'room_layout_id' => $this->layout->id, 'kind' => 'desk',
        'pos_x' => 100, 'pos_y' => 100, 'width' => 80, 'height' => 40, 'rotation' => 0, 'label' => null,
        'created_at' => now(), 'updated_at' => now(),
        ...$overrides,
    ];
});

it('accepts a well-formed fixture', function (): void {
    DB::table('floor_plan_fixtures')->insert(($this->fixture)(['label' => 'Teacher desk']));

    expect(DB::table('floor_plan_fixtures')->count())->toBe(1);
});

it('refuses an unknown kind (floor_plan_fixtures_kind_check)', function (): void {
    expect(fn () => DB::table('floor_plan_fixtures')->insert(($this->fixture)(['kind' => 'sofa'])))
        ->toThrow(QueryException::class, 'floor_plan_fixtures_kind_check');
});

it('refuses a zero-sized fixture (floor_plan_fixtures_size_check)', function (): void {
    expect(fn () => DB::table('floor_plan_fixtures')->insert(($this->fixture)(['width' => 0])))
        ->toThrow(QueryException::class, 'floor_plan_fixtures_size_check');
});

it('refuses a negative position (floor_plan_fixtures_position_check)', function (): void {
    expect(fn () => DB::table('floor_plan_fixtures')->insert(($this->fixture)(['pos_x' => -1])))
        ->toThrow(QueryException::class, 'floor_plan_fixtures_position_check');
});

it('refuses a full turn or more (floor_plan_fixtures_rotation_check)', function (): void {
    expect(fn () => DB::table('floor_plan_fixtures')->insert(($this->fixture)(['rotation' => 360])))
        ->toThrow(QueryException::class, 'floor_plan_fixtures_rotation_check');
});

it('refuses a whitespace-only label, tabs included (floor_plan_fixtures_label_check)', function (): void {
    expect(fn () => DB::table('floor_plan_fixtures')->insert(($this->fixture)(['label' => " \t "])))
        ->toThrow(QueryException::class, 'floor_plan_fixtures_label_check');
});

it('refuses the same fixture id twice on one layout', function (): void {
    $uuid = (string) Str::uuid();
    DB::table('floor_plan_fixtures')->insert(($this->fixture)(['uuid' => $uuid]));

    expect(fn () => DB::table('floor_plan_fixtures')->insert(($this->fixture)(['uuid' => $uuid])))
        ->toThrow(QueryException::class, 'floor_plan_fixtures_room_layout_id_uuid_unique');
});

it('allows the same fixture id on another layout — ids are per layout, never an oracle', function (): void {
    $uuid = (string) Str::uuid();
    $other = RoomLayout::factory()->create(['room_id' => Room::factory()->create()->id]);

    DB::table('floor_plan_fixtures')->insert(($this->fixture)(['uuid' => $uuid]));
    DB::table('floor_plan_fixtures')->insert(($this->fixture)(['uuid' => $uuid, 'room_layout_id' => $other->id]));

    expect(DB::table('floor_plan_fixtures')->where('uuid', $uuid)->count())->toBe(2);
});

it('deletes a layout\'s fixtures with the layout', function (): void {
    DB::table('floor_plan_fixtures')->insert(($this->fixture)());

    $this->layout->delete();

    expect(DB::table('floor_plan_fixtures')->count())->toBe(0);
});

it('refuses a workstation turned other than a quarter step (floor_plan_positions_rotation_check)', function (): void {
    $pc = PcUnit::factory()->create(['room_id' => $this->layout->room_id]);

    expect(fn () => FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $pc->id, 'rotation' => 45]))
        ->toThrow(QueryException::class, 'floor_plan_positions_rotation_check');
});

it('refuses a revision below one (room_layouts_revision_check)', function (): void {
    expect(fn () => DB::table('room_layouts')->where('id', $this->layout->id)->update(['revision' => 0]))
        ->toThrow(QueryException::class, 'room_layouts_revision_check');
});
