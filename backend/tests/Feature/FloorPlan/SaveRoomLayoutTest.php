<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Events\RoomPlanChanged;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\PermissionGrantType;
use App\Models\ActivityLog;
use App\Models\FloorPlanFixture;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Phase 2.11 — `PUT /api/admin/floor-plan/rooms/{room}/layouts/{version}`
 * (`SaveRoomLayout`, SDD DD-101/DD-102). The whole Floorplan in one
 * transaction: workstations, fixtures and the layout's size and grid.
 *
 * Replaces WP-D's per-machine PATCH; its guarantees are ported here —
 * authorization by policy ability (including the per-user-grant trap), no
 * existence oracle, no re-homing a machine, the active layout only, history on
 * each machine's own audit trail — and every refused write is checked against
 * the database afterwards.
 */
function saveGrant(User $user, string $permission, PermissionGrantType $type): void
{
    DB::table('user_permissions')->insert([
        'user_id' => $user->getKey(),
        'permission_id' => Permission::query()->where('name', $permission)->value('id'),
        'grant_type' => $type->value,
    ]);

    app(PermissionResolver::class)->forget($user);
}

beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->room = Room::factory()->create(['name' => 'Lab 1']);
    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true,
    ]);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC-01']);
    $this->other = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC-02']);
    $this->admin = userWithRole('administrator');

    $this->url = fn (?Room $room = null, int $version = 1): string => sprintf(
        '/api/admin/floor-plan/rooms/%s/layouts/%d', ($room ?? $this->room)->uuid, $version,
    );
    $this->doc = fn (array $overrides = []): array => [
        'expected_revision' => 1,
        'settings' => ['width' => 1000, 'height' => 600, 'grid_size' => 20],
        'workstations' => [],
        'fixtures' => [],
        ...$overrides,
    ];
    $this->ws = fn (PcUnit $pc, float $x, float $y, int $rotation = 0): array => [
        'pc_unit' => $pc->uuid, 'x' => $x, 'y' => $y, 'rotation' => $rotation,
    ];
    $this->fixture = fn (array $overrides = []): array => [
        'id' => (string) Str::uuid(), 'kind' => 'desk', 'x' => 200, 'y' => 200, 'width' => 80, 'height' => 40, 'rotation' => 0, 'label' => null,
        ...$overrides,
    ];
});

// ── What a save writes ──────────────────────────────────────────────────────

it('places workstations and answers with the saved plan and the next revision', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [
            ($this->ws)($this->pc, 100, 100, 90),
            ($this->ws)($this->other, 140, 100),
        ]]))
        ->assertOk()
        ->assertJsonPath('data.layout.revision', 2)
        ->assertJsonPath('data.pcs.0.id', $this->pc->uuid)
        ->assertJsonPath('data.pcs.0.rotation', 90)
        ->assertJsonPath('data.unplaced_count', 0);

    expect(FloorPlanPosition::query()->where('room_layout_id', $this->layout->id)->count())->toBe(2)
        ->and($this->layout->fresh()->revision)->toBe(2);
});

it('stores the coordinates the editor drew, rounded to storage precision — snapping is the editor\'s aid', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 103.456, 99.994)]]))
        ->assertOk()
        ->assertJsonPath('data.pcs.0.x', 103.46)
        ->assertJsonPath('data.pcs.0.y', 99.99);
});

it('moves and turns a placed workstation, and removes one left out of the document', function (): void {
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->pc->id, 'pos_x' => 100, 'pos_y' => 100]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->other->id, 'pos_x' => 200, 'pos_y' => 100]);

    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 300, 300, 180)]]))
        ->assertOk()
        ->assertJsonPath('data.unplaced.0.id', $this->other->uuid);

    $moved = FloorPlanPosition::query()->where('pc_unit_id', $this->pc->id)->sole();

    expect((float) $moved->pos_x)->toBe(300.0)
        ->and((int) round((float) $moved->rotation))->toBe(180)
        ->and(FloorPlanPosition::query()->where('pc_unit_id', $this->other->id)->exists())->toBeFalse();
});

it('records each machine\'s change on its own history, and one summary on the layout', function (): void {
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->other->id, 'pos_x' => 200, 'pos_y' => 100]);

    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100)], 'fixtures' => [($this->fixture)()]]))
        ->assertOk();

    $placed = ActivityLog::query()->where('action', ActivityAction::AssetPositionChanged->value)->where('subject_id', $this->pc->id)->sole();
    $cleared = ActivityLog::query()->where('action', ActivityAction::AssetPositionCleared->value)->where('subject_id', $this->other->id)->sole();
    $summary = ActivityLog::query()->where('action', ActivityAction::LayoutUpdated->value)->where('subject_id', $this->layout->id)->sole();

    expect($placed->properties['from'])->toBeNull()
        ->and($placed->properties['to'])->toEqual(['x' => 100, 'y' => 100, 'rotation' => 0])
        ->and($cleared->properties['reason'])->toBe('removed_from_plan')
        ->and($summary->properties['workstations'])->toEqual(['placed' => 1, 'moved' => 0, 'removed' => 1])
        ->and($summary->properties['fixtures'])->toEqual(['added' => 1, 'changed' => 0, 'removed' => 0])
        ->and($summary->properties['revision'])->toBe(2);
});

it('saves fixtures with their client ids, labels and free rotation', function (): void {
    $id = (string) Str::uuid();

    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['fixtures' => [
            ($this->fixture)(['id' => $id, 'kind' => 'zone', 'x' => 500, 'y' => 300, 'width' => 300, 'height' => 200, 'rotation' => 15, 'label' => 'Teacher area']),
        ]]))
        ->assertOk()
        ->assertJsonPath('data.fixtures.0.id', $id)
        ->assertJsonPath('data.fixtures.0.kind', 'zone')
        ->assertJsonPath('data.fixtures.0.rotation', 15)
        ->assertJsonPath('data.fixtures.0.label', 'Teacher area');
});

it('replaces the fixture set wholesale, so a fixture left out is deleted', function (): void {
    FloorPlanFixture::query()->create([
        'uuid' => (string) Str::uuid(), 'room_layout_id' => $this->layout->id, 'kind' => 'wall',
        'pos_x' => 100, 'pos_y' => 100, 'width' => 200, 'height' => 8, 'rotation' => 0,
    ]);

    $this->actingAs($this->admin)->putJson(($this->url)(), ($this->doc)())->assertOk()->assertJsonPath('data.fixtures', []);

    expect(FloorPlanFixture::query()->count())->toBe(0);
});

it('resizes the room and changes its grid in the same save', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['settings' => ['width' => 1200, 'height' => 800, 'grid_size' => 10]]))
        ->assertOk()
        ->assertJsonPath('data.layout.width', 1200)
        ->assertJsonPath('data.layout.grid_size', 10);
});

it('does not advance the revision for a save that changes nothing', function (): void {
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->pc->id, 'pos_x' => 100, 'pos_y' => 100]);
    Event::fake([RoomPlanChanged::class]);

    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100)]]))
        ->assertOk()
        ->assertJsonPath('data.layout.revision', 1);

    Event::assertNotDispatched(RoomPlanChanged::class);
});

it('tells the room\'s other editors, by name only, once the save has committed', function (): void {
    Event::fake([RoomPlanChanged::class]);

    $this->actingAs($this->admin)->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100)]]))->assertOk();

    Event::assertDispatched(RoomPlanChanged::class, fn (RoomPlanChanged $e) => $e->roomUuid === $this->room->uuid
        && $e->revision === 2
        && $e->cause === 'layout_saved'
        && $e->broadcastWith()['by'] === ['name' => $this->admin->fullName()]);
});

// ── Geometry rules ──────────────────────────────────────────────────────────

it('refuses a workstation that sticks out of the room', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 990, 100)]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workstations.0.x');

    expect(FloorPlanPosition::query()->count())->toBe(0)->and($this->layout->fresh()->revision)->toBe(1);
});

it('refuses two workstations that overlap, naming both', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100), ($this->ws)($this->other, 120, 100)]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workstations.0.x', 'workstations.1.x']);
});

it('accepts workstations that only touch — rows are laid edge to edge', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100), ($this->ws)($this->other, 140, 100)]]))
        ->assertOk();
});

it('lets a workstation stand on a desk — fixtures may overlap anything', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)([
            'workstations' => [($this->ws)($this->pc, 200, 200)],
            'fixtures' => [($this->fixture)(['x' => 200, 'y' => 200])],
        ]))
        ->assertOk();
});

it('refuses a rotated fixture whose corner leaves the room', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['fixtures' => [($this->fixture)(['kind' => 'wall', 'x' => 100, 'y' => 20, 'width' => 200, 'height' => 8, 'rotation' => 45])]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fixtures.0.x');
});

it('refuses a resize that would leave a placed workstation outside the room', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)([
            'settings' => ['width' => 300, 'height' => 600, 'grid_size' => 20],
            'workstations' => [($this->ws)($this->pc, 500, 100)],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workstations.0.x');
});

it('turns a workstation only in quarter steps', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100, 45)]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workstations.0.rotation');
});

it('refuses the same machine twice', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100), ($this->ws)($this->pc, 300, 100)]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workstations.0.pc_unit');
});

it('refuses an unknown fixture kind', function (): void {
    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['fixtures' => [($this->fixture)(['kind' => 'sofa'])]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fixtures.0.kind');
});

it('refuses more fixtures than a layout may hold', function (): void {
    $fixtures = array_map(fn (): array => ($this->fixture)(), range(1, 501));

    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['fixtures' => $fixtures]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fixtures');
});

// ── Never a room change ─────────────────────────────────────────────────────

it('refuses a machine from another room rather than moving it here', function (): void {
    $elsewhere = PcUnit::factory()->create(['room_id' => Room::factory()->create()->id]);

    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($elsewhere, 100, 100)]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workstations.0.pc_unit');

    expect($elsewhere->fresh()->room_id)->not->toBe($this->room->id)
        ->and(FloorPlanPosition::query()->count())->toBe(0);
});

it('refuses an archived machine', function (): void {
    $this->pc->delete();

    $this->actingAs($this->admin)
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100)]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workstations.0.pc_unit');
});

it('leaves the position of a machine that has since left the room untouched — it is history', function (): void {
    $gone = PcUnit::factory()->create(['room_id' => Room::factory()->create()->id]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $gone->id, 'pos_x' => 500, 'pos_y' => 500]);

    $this->actingAs($this->admin)->putJson(($this->url)(), ($this->doc)())->assertOk();

    expect(FloorPlanPosition::query()->where('pc_unit_id', $gone->id)->exists())->toBeTrue();
});

// ── Addressing and the active layout ────────────────────────────────────────

it('refuses to edit an inactive version — history is not rewritten', function (): void {
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'is_active' => false]);

    $this->actingAs($this->admin)
        ->putJson(($this->url)(null, 2), ($this->doc)())
        ->assertStatus(409)
        ->assertJsonPath('code', 'layout_not_active');
});

it('answers 404 for a version of another room', function (): void {
    $otherRoom = Room::factory()->create();
    RoomLayout::factory()->create(['room_id' => $otherRoom->id, 'version' => 7, 'is_active' => true]);

    $this->actingAs($this->admin)->putJson(($this->url)(null, 7), ($this->doc)())->assertNotFound();
});

it('answers 404 for an unknown room', function (): void {
    $this->actingAs($this->admin)
        ->putJson(sprintf('/api/admin/floor-plan/rooms/%s/layouts/1', Str::uuid()), ($this->doc)())
        ->assertNotFound();
});

// ── Authorization (role by role, plus the per-user-grant trap) ──────────────

it('refuses every non-administrator role, and writes nothing', function (string $role): void {
    $this->actingAs(userWithRole($role))
        ->putJson(($this->url)(), ($this->doc)(['workstations' => [($this->ws)($this->pc, 100, 100)]]))
        ->assertForbidden();

    expect(FloorPlanPosition::query()->count())->toBe(0);
})->with(['technician', 'teacher']);

it('refuses a technician with a per-user floorplan.manage grant — the Gate::before trap', function (): void {
    $technician = userWithRole('technician');
    saveGrant($technician, 'floorplan.view', PermissionGrantType::Grant);
    saveGrant($technician, 'floorplan.manage', PermissionGrantType::Grant);

    $this->actingAs($technician)->putJson(($this->url)(), ($this->doc)())->assertForbidden();
});

it('refuses an administrator whose floorplan.manage is denied per user', function (): void {
    saveGrant($this->admin, 'floorplan.manage', PermissionGrantType::Deny);

    $this->actingAs($this->admin)->putJson(($this->url)(), ($this->doc)())->assertForbidden();
});

it('answers 403, not 404, to a non-administrator naming an unknown room — no existence oracle', function (): void {
    $this->actingAs(userWithRole('teacher'))
        ->putJson(sprintf('/api/admin/floor-plan/rooms/%s/layouts/1', Str::uuid()), ($this->doc)())
        ->assertForbidden();
});

it('refuses a guest', function (): void {
    $this->putJson(($this->url)(), ($this->doc)())->assertUnauthorized();
});
