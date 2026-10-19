<?php

declare(strict_types=1);

use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;

/**
 * Phase 2.11 — the whole-layout revision (SDD DD-101). Ports WP-F's
 * two-administrators race to the Floorplan save: a save is refused with 409
 * `layout_stale` and the **current** plan when someone else saved — or a
 * machine left the room — since the editor loaded the layout; retrying with
 * the current revision succeeds; nothing is ever silently overwritten.
 */
beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->room = Room::factory()->create();
    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true,
    ]);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC-01']);
    $this->ana = userWithRole('administrator');
    $this->ben = userWithRole('administrator');

    $this->url = sprintf('/api/admin/floor-plan/rooms/%s/layouts/1', $this->room->uuid);
    $this->save = fn (int $revision, float $x): array => [
        'expected_revision' => $revision,
        'settings' => ['width' => 1000, 'height' => 600, 'grid_size' => 20],
        'workstations' => [['pc_unit' => $this->pc->uuid, 'x' => $x, 'y' => 100, 'rotation' => 0]],
        'fixtures' => [],
    ];
});

it('refuses the second of two saves made from the same revision, and hands back the current plan', function (): void {
    $this->actingAs($this->ana)->putJson($this->url, ($this->save)(1, 100))->assertOk();

    $this->actingAs($this->ben)
        ->putJson($this->url, ($this->save)(1, 300))
        ->assertStatus(409)
        ->assertJsonPath('code', 'layout_stale')
        ->assertJsonPath('current.layout.revision', 2)
        ->assertJsonPath('current.pcs.0.x', 100)
        ->assertJsonPath('current.layout.updated_by.name', $this->ana->fullName());

    // Ana's save stands; Ben's was not silently applied over it.
    expect((float) FloorPlanPosition::query()->sole()->pos_x)->toBe(100.0);
});

it('accepts the retry once the editor has caught up to the current revision', function (): void {
    $this->actingAs($this->ana)->putJson($this->url, ($this->save)(1, 100))->assertOk();

    $this->actingAs($this->ben)
        ->putJson($this->url, ($this->save)(2, 300))
        ->assertOk()
        ->assertJsonPath('data.layout.revision', 3);
});

it('makes a draft stale when a placed machine leaves the room', function (): void {
    $this->actingAs($this->ana)->putJson($this->url, ($this->save)(1, 100))->assertOk();

    // The machine moves rooms through the ordinary asset edit — which releases
    // its place here and bumps the revision (DD-104).
    $this->actingAs($this->ana)
        ->putJson("/api/admin/pc-units/{$this->pc->uuid}", ['room' => Room::factory()->create()->uuid])
        ->assertOk();

    $this->actingAs($this->ben)
        ->putJson($this->url, ($this->save)(2, 300))
        ->assertStatus(409)
        ->assertJsonPath('code', 'layout_stale')
        ->assertJsonPath('current.pcs', []);
});

it('rejects a revision the layout never had', function (): void {
    $this->actingAs($this->ana)
        ->putJson($this->url, ($this->save)(99, 100))
        ->assertStatus(409)
        ->assertJsonPath('code', 'layout_stale');

    expect(FloorPlanPosition::query()->count())->toBe(0);
});
