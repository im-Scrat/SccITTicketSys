<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\PcStatus;
use App\Enums\PermissionGrantType;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WP-D — `PATCH /api/admin/floor-plan/rooms/{room}/layouts/{version}/positions/{pcUnit}`.
 *
 * The server is the authority on where a machine ends up: it rejects a point
 * off the canvas, snaps, clamps and stores, and answers with what it stored.
 * Every refusal is asserted with *real* identifiers, and every refused write is
 * checked against the database afterwards — a 403 that still moved the machine
 * would be worse than no test.
 */
beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->room = Room::factory()->create();
    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true,
    ]);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'status' => PcStatus::Online->value, 'pc_name' => 'PC-01']);
    $this->position = FloorPlanPosition::factory()->create([
        'room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->pc->id, 'pos_x' => 120, 'pos_y' => 80, 'rotation' => 45, 'z_index' => 3,
    ]);

    $this->admin = userWithRole('administrator');
    $this->url = fn (?PcUnit $pc = null, ?Room $room = null, int $version = 1): string => sprintf(
        '/api/admin/floor-plan/rooms/%s/layouts/%d/positions/%s',
        ($room ?? $this->room)->uuid,
        $version,
        ($pc ?? $this->pc)->uuid,
    );
    $this->stored = fn (?PcUnit $pc = null): ?array => FloorPlanPosition::query()
        ->where('room_layout_id', $this->layout->id)
        ->where('pc_unit_id', ($pc ?? $this->pc)->id)
        ->first(['pos_x', 'pos_y'])
        ?->only(['pos_x', 'pos_y']);
});

function grantPerUser(User $user, string $permission, PermissionGrantType $type): void
{
    DB::table('user_permissions')->insert([
        'user_id' => $user->getKey(),
        'permission_id' => Permission::query()->where('name', $permission)->value('id'),
        'grant_type' => $type->value,
    ]);

    app(PermissionResolver::class)->forget($user);
}

// ── Server-authoritative placement ──────────────────────────────────────────

it('moves a placed unit and answers with the stored, snapped point', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 127, 'y' => 83])
        ->assertOk()
        ->assertJsonPath('data.id', $this->pc->uuid)
        ->assertJsonPath('data.x', 120)
        ->assertJsonPath('data.y', 80);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 331, 'y' => 249])
        ->assertOk()
        ->assertJsonPath('data.x', 340)
        ->assertJsonPath('data.y', 240);

    expect(($this->stored)())->toBe(['pos_x' => '340.00', 'pos_y' => '240.00']);
});

it('rounds half-way points away from zero, like CoordinateService', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 130, 'y' => 50])
        ->assertOk()
        ->assertJsonPath('data.x', 140)
        ->assertJsonPath('data.y', 60);
});

it('ignores a client that claims to have snapped already: the server snaps again', function (): void {
    // 125,75 is "on the grid" for a client that believes the grid is 25 px.
    // The layout says 20, and the layout wins.
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 125, 'y' => 75, 'snap' => true])
        ->assertOk()
        ->assertJsonPath('data.x', 120)
        ->assertJsonPath('data.y', 80);
});

it('places freely, to storage precision, only when snapping is explicitly off', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 127.456, 'y' => 83.004, 'snap' => false])
        ->assertOk()
        ->assertJsonPath('data.x', 127.46)
        ->assertJsonPath('data.y', 83);

    expect(($this->stored)())->toBe(['pos_x' => '127.46', 'pos_y' => '83.00']);
});

it('takes the snap default from the floor_plan.snap_to_grid setting', function (): void {
    SystemSetting::factory()->create([
        'group' => 'floor_plan', 'key' => 'floor_plan.snap_to_grid', 'value' => false, 'type' => 'boolean',
    ]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 127, 'y' => 83])
        ->assertOk()
        ->assertJsonPath('data.x', 127)
        ->assertJsonPath('data.y', 83);
});

it('snaps by default when the setting is absent', function (): void {
    expect(SystemSetting::query()->where('key', 'floor_plan.snap_to_grid')->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 127, 'y' => 83])
        ->assertOk()
        ->assertJsonPath('data.x', 120);
});

it('clamps a snapped point that the grid pushed past the edge back onto the canvas', function (): void {
    // 1010 wide on a 20 px grid: 1010 rounds to 1020, one grid line off the plan.
    $this->layout->update(['width' => 1010, 'height' => 610]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 1010, 'y' => 610])
        ->assertOk()
        ->assertJsonPath('data.x', 1010)
        ->assertJsonPath('data.y', 610);
});

it('accepts the canvas edges themselves', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 1000, 'y' => 600])
        ->assertOk()
        ->assertJsonPath('data.x', 1000)
        ->assertJsonPath('data.y', 600);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 0, 'y' => 0])
        ->assertOk()
        ->assertJsonPath('data.x', 0)
        ->assertJsonPath('data.y', 0);
});

it('rejects a point off this layout\'s canvas instead of dragging it inside', function (array $point, string $field): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), $point)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
})->with([
    'past the width' => [['x' => 1000.01, 'y' => 100], 'x'],
    'past the height' => [['x' => 100, 'y' => 600.5], 'y'],
    'negative x' => [['x' => -1, 'y' => 100], 'x'],
    'negative y' => [['x' => 100, 'y' => -0.01], 'y'],
]);

it('measures bounds against the layout\'s own width and height', function (): void {
    $this->layout->update(['width' => 400, 'height' => 300]);

    // On a 1000×600 plan this point would be fine.
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 500, 'y' => 100])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('x')
        ->assertJsonPath('errors.x.0', 'The x position must be between 0 and 400.');

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 100, 'y' => 350])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('y')
        ->assertJsonPath('errors.y.0', 'The y position must be between 0 and 300.');
});

it('rejects missing, non-numeric and non-finite coordinates', function (array $payload, string $field): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
})->with([
    'missing x' => [['y' => 10], 'x'],
    'missing y' => [['x' => 10], 'y'],
    'text' => [['x' => 'left', 'y' => 10], 'x'],
    'overflow to infinity' => [['x' => '1e400', 'y' => 10], 'x'],
    'snap not a boolean' => [['x' => 10, 'y' => 10, 'snap' => 'sometimes'], 'snap'],
]);

it('keeps the rotation and stacking order when a unit moves', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertOk()
        ->assertJsonPath('data.rotation', 45)
        ->assertJsonPath('data.z_index', 3);
});

it('places an unplaced unit of the room, creating its position (201)', function (): void {
    $fresh = PcUnit::factory()->create(['room_id' => $this->room->id]);

    // An upsert that created the row says so; a move of an existing one is 200.
    $this->actingAs($this->admin)
        ->patchJson(($this->url)($fresh), ['x' => 401, 'y' => 219])
        ->assertCreated()
        ->assertJsonPath('data.id', $fresh->uuid)
        ->assertJsonPath('data.x', 400)
        ->assertJsonPath('data.y', 220)
        ->assertJsonPath('data.rotation', 0)
        ->assertJsonPath('data.z_index', 0);

    expect(($this->stored)($fresh))->toBe(['pos_x' => '400.00', 'pos_y' => '220.00'])
        ->and(FloorPlanPosition::query()->where('pc_unit_id', $fresh->id)->count())->toBe(1);
});

it('answers with the same narrow shape as the map, and no numeric id', function (): void {
    $response = $this->actingAs($this->admin)->patchJson(($this->url)(), ['x' => 200, 'y' => 200])->assertOk();

    expect(array_keys($response->json('data')))
        ->toBe(['id', 'name', 'unit_code', 'status', 'x', 'y', 'rotation', 'z_index', 'updated_at']);

    $encoded = (string) $response->getContent();
    foreach (['serial_number', 'ip_address', 'mac_address', 'asset_tag', 'hostname', 'room_id', 'pc_unit_id', 'room_layout_id'] as $field) {
        expect($encoded)->not->toContain($field);
    }
});

// ── Room membership: this endpoint is never an asset transfer ───────────────

it('refuses a unit from another room as not found, and moves nothing', function (): void {
    $elsewhere = Room::factory()->create();
    $stranger = PcUnit::factory()->create(['room_id' => $elsewhere->id]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)($stranger), ['x' => 200, 'y' => 200])
        ->assertNotFound();

    expect($stranger->fresh()->room_id)->toBe($elsewhere->id)
        ->and(FloorPlanPosition::query()->where('pc_unit_id', $stranger->id)->exists())->toBeFalse();
});

it('refuses a unit that has been transferred out of the room since it was placed', function (): void {
    $elsewhere = Room::factory()->create();
    $this->pc->update(['room_id' => $elsewhere->id]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertNotFound();

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00'])
        ->and($this->pc->fresh()->room_id)->toBe($elsewhere->id);
});

it('refuses an archived unit', function (): void {
    $this->pc->delete();

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertNotFound();
});

it('refuses a unit with no room at all', function (): void {
    $homeless = PcUnit::factory()->create(['room_id' => null]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)($homeless), ['x' => 200, 'y' => 200])
        ->assertNotFound();

    expect(FloorPlanPosition::query()->where('pc_unit_id', $homeless->id)->exists())->toBeFalse();
});

it('cannot be used to put a unit on another room\'s plan by swapping the room', function (): void {
    // The attacker's plan: address a room they chose, with a machine from here.
    $target = Room::factory()->create();
    RoomLayout::factory()->create(['room_id' => $target->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)($this->pc, $target), ['x' => 200, 'y' => 200])
        ->assertNotFound();

    expect($this->pc->fresh()->room_id)->toBe($this->room->id)
        ->and(FloorPlanPosition::query()->where('pc_unit_id', $this->pc->id)->count())->toBe(1);
});

// ── Active layout ───────────────────────────────────────────────────────────

it('edits only the active layout: a historical version is refused with 409', function (): void {
    $this->layout->update(['is_active' => false]);
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'width' => 1000, 'height' => 600, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertStatus(409)
        ->assertJsonPath('code', 'layout_not_active');

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
});

it('answers a version the room does not have with 404', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(version: 7), ['x' => 200, 'y' => 200])
        ->assertNotFound();
});

it('never resolves another room\'s layout version through this room', function (): void {
    // Room B has a version 2; room A does not. Asking room A for v2 is a 404,
    // not an edit of room B's plan.
    $other = Room::factory()->create();
    RoomLayout::factory()->create(['room_id' => $other->id, 'version' => 2, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(version: 2), ['x' => 200, 'y' => 200])
        ->assertNotFound();
});

// ── One machine per spot ────────────────────────────────────────────────────

it('refuses a point already taken by another unit in the room', function (): void {
    $neighbour = PcUnit::factory()->create(['room_id' => $this->room->id]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $neighbour->id, 'pos_x' => 300, 'pos_y' => 300]);

    // 297,303 snaps onto 300,300.
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 297, 'y' => 303])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'position_occupied');

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
});

it('lets a unit be dropped back on its own spot', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 120, 'y' => 80])
        ->assertOk()
        ->assertJsonPath('data.x', 120);
});

it('does not let a stale row of a transferred unit block a spot that looks empty', function (): void {
    $gone = PcUnit::factory()->create(['room_id' => $this->room->id]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $gone->id, 'pos_x' => 300, 'pos_y' => 300]);
    $gone->update(['room_id' => Room::factory()->create()->id]);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 300, 'y' => 300])
        ->assertOk();
});

// ── Authorization and direct identifiers ────────────────────────────────────

it('requires authentication', function (): void {
    $this->patchJson(($this->url)(), ['x' => 200, 'y' => 200])->assertUnauthorized();

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
});

it('refuses a Teacher and a Technician with real identifiers, and moves nothing', function (string $role): void {
    $this->actingAs(userWithRole($role))
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertForbidden();

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
})->with(['technician', 'teacher']);

it('refuses a non-administrator identically for real, unknown and archived identifiers', function (string $role): void {
    $actor = userWithRole($role);
    $archivedRoom = Room::factory()->create();
    $archivedRoom->delete();
    $unknown = (string) Str::uuid();

    $urls = [
        ($this->url)(),
        sprintf('/api/admin/floor-plan/rooms/%s/layouts/1/positions/%s', $unknown, $this->pc->uuid),
        sprintf('/api/admin/floor-plan/rooms/%s/layouts/1/positions/%s', $this->room->uuid, $unknown),
        sprintf('/api/admin/floor-plan/rooms/%s/layouts/9/positions/%s', $this->room->uuid, $this->pc->uuid),
        sprintf('/api/admin/floor-plan/rooms/%s/layouts/1/positions/%s', $archivedRoom->uuid, $this->pc->uuid),
    ];

    // No 404 that would tell a real room, version or machine from an invented one.
    foreach ($urls as $url) {
        $this->actingAs($actor)->patchJson($url, ['x' => 200, 'y' => 200])->assertForbidden();
    }
})->with(['technician', 'teacher']);

it('still refuses a Technician or Teacher individually granted floorplan.view and floorplan.manage', function (string $role): void {
    $actor = userWithRole($role);
    grantPerUser($actor, 'floorplan.view', PermissionGrantType::Grant);
    grantPerUser($actor, 'floorplan.manage', PermissionGrantType::Grant);

    // The grants took effect …
    expect($actor->hasPermissionTo('floorplan.manage'))->toBeTrue();

    // … and open nothing.
    $this->actingAs($actor)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertForbidden();

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
})->with(['technician', 'teacher']);

it('lets an Administrator whose floorplan.manage is denied view the map but not move anything', function (): void {
    grantPerUser($this->admin, 'floorplan.manage', PermissionGrantType::Deny);

    $this->actingAs($this->admin)
        ->getJson("/api/admin/floor-plan/rooms/{$this->room->uuid}")
        ->assertOk()
        ->assertJsonPath('data.editor.can_edit', false);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertForbidden();

    expect(($this->stored)())->toBe(['pos_x' => '120.00', 'pos_y' => '80.00']);
});

it('refuses an Administrator whose floorplan.view is denied', function (): void {
    grantPerUser($this->admin, 'floorplan.view', PermissionGrantType::Deny);

    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200])
        ->assertForbidden();
});

it('answers junk and numeric identifiers with 404 at the router', function (): void {
    $junk = [
        sprintf('/api/admin/floor-plan/rooms/%d/layouts/1/positions/%s', $this->room->id, $this->pc->uuid),
        sprintf('/api/admin/floor-plan/rooms/%s/layouts/1/positions/%d', $this->room->uuid, $this->pc->id),
        sprintf('/api/admin/floor-plan/rooms/%s/layouts/latest/positions/%s', $this->room->uuid, $this->pc->uuid),
        sprintf('/api/admin/floor-plan/rooms/%s/layouts/1/positions/%s', $this->room->uuid, rawurlencode("1' OR '1'='1")),
    ];

    foreach ($junk as $url) {
        $this->actingAs($this->admin)->patchJson($url, ['x' => 200, 'y' => 200])->assertNotFound();
    }
});

it('answers an unknown room or machine uuid with 404 for an Administrator', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(sprintf('/api/admin/floor-plan/rooms/%s/layouts/1/positions/%s', Str::uuid(), $this->pc->uuid), ['x' => 200, 'y' => 200])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->patchJson(sprintf('/api/admin/floor-plan/rooms/%s/layouts/1/positions/%s', $this->room->uuid, Str::uuid()), ['x' => 200, 'y' => 200])
        ->assertNotFound();
});

it('does not accept the write on the map endpoint or any other verb', function (): void {
    $this->actingAs($this->admin)->putJson(($this->url)(), ['x' => 200, 'y' => 200])->assertStatus(405);
    $this->actingAs($this->admin)->postJson(($this->url)(), ['x' => 200, 'y' => 200])->assertStatus(405);
});
