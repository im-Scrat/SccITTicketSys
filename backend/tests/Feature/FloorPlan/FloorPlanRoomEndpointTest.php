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
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * WP-C — `GET /api/admin/floor-plan/rooms/{room}`: the read-only map payload.
 *
 * The negative cases lead. The endpoint's whole risk is that a valid uuid, or a
 * permission someone was individually granted, gets a caller in — so every
 * refusal below is asserted with a *real* room uuid, and the per-user-grant
 * cases are the ones WP-B identified as the trap (`Gate::before`).
 */
beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->room = Room::factory()->create();
    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true,
    ]);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'status' => PcStatus::Online->value]);
    FloorPlanPosition::factory()->create([
        'room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->pc->id, 'pos_x' => 120, 'pos_y' => 80, 'rotation' => 0,
    ]);

    $this->url = fn (string $uuid) => "/api/admin/floor-plan/rooms/{$uuid}";
});

function grantFloorPlanPermissions(User $user): void
{
    foreach (['floorplan.view', 'floorplan.manage'] as $name) {
        DB::table('user_permissions')->insert([
            'user_id' => $user->getKey(),
            'permission_id' => Permission::query()->where('name', $name)->value('id'),
            'grant_type' => PermissionGrantType::Grant->value,
        ]);
    }

    app(PermissionResolver::class)->forget($user);
}

// ── Who may open it ─────────────────────────────────────────────────────────

it('requires authentication', function (): void {
    $this->getJson(($this->url)($this->room->uuid))->assertUnauthorized();
});

it('serves an Administrator the active layout and its placed PC units', function (): void {
    $this->actingAs(userWithRole('administrator'))
        ->getJson(($this->url)($this->room->uuid))
        ->assertOk()
        ->assertJsonPath('data.room.id', $this->room->uuid)
        ->assertJsonPath('data.layout.version', 1)
        ->assertJsonPath('data.layout.width', 1000)
        ->assertJsonPath('data.layout.height', 600)
        ->assertJsonPath('data.layout.grid_size', 20)
        ->assertJsonCount(1, 'data.pcs')
        ->assertJsonPath('data.pcs.0.id', $this->pc->uuid)
        ->assertJsonPath('data.pcs.0.x', 120)
        ->assertJsonPath('data.pcs.0.y', 80)
        ->assertJsonPath('data.unplaced_count', 0);
});

it('refuses a Teacher and a Technician with a real room uuid', function (string $role): void {
    $this->actingAs(userWithRole($role))
        ->getJson(($this->url)($this->room->uuid))
        ->assertForbidden();
})->with(['technician', 'teacher']);

it('refuses a non-administrator identically for a real, an unknown and an archived room', function (string $role): void {
    $archived = Room::factory()->create();
    $archived->delete();
    $actor = userWithRole($role);

    // No 404 that would tell a real room from an invented one.
    $this->actingAs($actor)->getJson(($this->url)($this->room->uuid))->assertForbidden();
    $this->actingAs($actor)->getJson(($this->url)((string) Str::uuid()))->assertForbidden();
    $this->actingAs($actor)->getJson(($this->url)($archived->uuid))->assertForbidden();
})->with(['technician', 'teacher']);

it('still refuses a Technician or Teacher who was individually granted floorplan.view and floorplan.manage', function (string $role): void {
    $actor = userWithRole($role);
    grantFloorPlanPermissions($actor);

    // The grant took effect …
    expect($actor->hasPermissionTo('floorplan.view'))->toBeTrue()
        ->and($actor->hasPermissionTo('floorplan.manage'))->toBeTrue();

    // … and opens nothing.
    $this->actingAs($actor)->getJson(($this->url)($this->room->uuid))->assertForbidden();
})->with(['technician', 'teacher']);

it('honours a per-user deny on an Administrator', function (): void {
    $admin = userWithRole('administrator');
    DB::table('user_permissions')->insert([
        'user_id' => $admin->id,
        'permission_id' => Permission::query()->where('name', 'floorplan.view')->value('id'),
        'grant_type' => PermissionGrantType::Deny->value,
    ]);
    app(PermissionResolver::class)->forget($admin);

    $this->actingAs($admin)->getJson(($this->url)($this->room->uuid))->assertForbidden();
});

it('has no permission-string gate on any floor-plan route', function (): void {
    // A structural guard for every later package: a route under /floor-plan
    // authorized by `can:floorplan.*` would open for a per-user grant, because
    // Gate::before answers permission-named abilities before any policy runs.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'floor-plan'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

        expect($middleware->filter(fn (string $m) => str_starts_with($m, 'can:floorplan.')))->toBeEmpty()
            ->and($middleware->filter(fn (string $m) => str_starts_with($m, 'can:')))->not->toBeEmpty();
    }
});

// ── Identifiers ─────────────────────────────────────────────────────────────

it('answers junk and numeric identifiers with 404 for everyone', function (string $role): void {
    $actor = userWithRole($role);

    foreach (['not-a-uuid', (string) $this->room->id, "1' OR '1'='1"] as $bad) {
        $this->actingAs($actor)->getJson(($this->url)(rawurlencode($bad)))->assertNotFound();
    }
})->with(['administrator', 'technician', 'teacher']);

it('answers an unknown uuid and an archived room with 404 for an Administrator', function (): void {
    $archived = Room::factory()->create();
    $archived->delete();
    $admin = userWithRole('administrator');

    $this->actingAs($admin)->getJson(($this->url)((string) Str::uuid()))->assertNotFound();
    $this->actingAs($admin)->getJson(($this->url)($archived->uuid))->assertNotFound();
});

it('is read-only: every write verb is refused', function (): void {
    $admin = userWithRole('administrator');
    $uri = ($this->url)($this->room->uuid);

    $this->actingAs($admin)->postJson($uri)->assertStatus(405);
    $this->actingAs($admin)->putJson($uri)->assertStatus(405);
    $this->actingAs($admin)->patchJson($uri)->assertStatus(405);
    $this->actingAs($admin)->deleteJson($uri)->assertStatus(405);
});

// ── Room membership and the active layout ──────────────────────────────────

it('never mixes another room\'s layout or PC units into the payload', function (): void {
    $other = Room::factory()->create();
    $otherLayout = RoomLayout::factory()->create(['room_id' => $other->id, 'version' => 1, 'width' => 500, 'height' => 400]);
    $otherPc = PcUnit::factory()->create(['room_id' => $other->id, 'pc_name' => 'OTHER-ROOM-PC']);
    FloorPlanPosition::factory()->create(['room_layout_id' => $otherLayout->id, 'pc_unit_id' => $otherPc->id]);

    $response = $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))->assertOk();

    $response->assertJsonPath('data.layout.width', 1000)->assertJsonCount(1, 'data.pcs');

    // Asserted against the encoded payload, so a later resource change cannot
    // let it back in unnoticed.
    expect($response->getContent())
        ->not->toContain($otherPc->uuid)
        ->not->toContain('OTHER-ROOM-PC')
        ->not->toContain($other->uuid);
});

it('drops a position whose PC unit has since moved to another room', function (): void {
    $other = Room::factory()->create();
    $moved = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'MOVED-AWAY']);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $moved->id]);

    // Transferred: the stale position row still points at this layout.
    $moved->update(['room_id' => $other->id]);

    $response = $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))->assertOk();

    $response->assertJsonCount(1, 'data.pcs');
    expect($response->getContent())->not->toContain('MOVED-AWAY')->not->toContain($moved->uuid);
});

it('drops a position row that names a PC unit which was never in the room', function (): void {
    $stranger = PcUnit::factory()->create(['room_id' => Room::factory()->create()->id, 'pc_name' => 'STRANGER-PC']);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $stranger->id]);

    $response = $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))->assertOk();

    $response->assertJsonCount(1, 'data.pcs');
    expect($response->getContent())->not->toContain('STRANGER-PC');
});

it('drops an archived PC unit', function (): void {
    $this->pc->delete();

    $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))
        ->assertOk()
        ->assertJsonCount(0, 'data.pcs');
});

it('serves only the active layout, never a historical version', function (): void {
    $this->layout->update(['is_active' => false]);
    $v2 = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 2, 'width' => 1400, 'height' => 900, 'is_active' => true,
    ]);

    $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))
        ->assertOk()
        ->assertJsonPath('data.layout.version', 2)
        ->assertJsonPath('data.layout.width', 1400)
        // v1's placement belongs to v1; v2 has none yet.
        ->assertJsonCount(0, 'data.pcs')
        ->assertJsonPath('data.unplaced_count', 1);

    expect($v2->is_active)->toBeTrue();
});

it('answers a room with no active layout with an empty plan, not an error', function (): void {
    $this->layout->update(['is_active' => false]);

    $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))
        ->assertOk()
        ->assertJsonPath('data.layout', null)
        ->assertJsonCount(0, 'data.pcs')
        ->assertJsonPath('data.unplaced_count', 1);
});

it('lists the room\'s unplaced units — narrowly, and never another room\'s or an archived one', function (): void {
    $waiting = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'WAITING-PC', 'status' => PcStatus::Offline->value]);
    PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'ARCHIVED-PC'])->delete();
    PcUnit::factory()->create(['room_id' => Room::factory()->create()->id, 'pc_name' => 'ELSEWHERE-PC']);

    $response = $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))->assertOk();

    $response->assertJsonCount(1, 'data.unplaced')
        ->assertJsonPath('data.unplaced.0.id', $waiting->uuid)
        ->assertJsonPath('data.unplaced.0.name', 'WAITING-PC')
        ->assertJsonPath('data.unplaced.0.status', [
            'value' => 'offline', 'label' => PcStatus::Offline->label(), 'tone' => PcStatus::Offline->tone(),
        ])
        ->assertJsonPath('data.unplaced_count', 1);

    expect(array_keys($response->json('data.unplaced.0')))->toBe(['id', 'name', 'unit_code', 'status'])
        ->and($response->getContent())
        ->not->toContain('ARCHIVED-PC')
        ->not->toContain('ELSEWHERE-PC')
        ->not->toContain((string) $waiting->serial_number);
});

it('tells an Administrator the plan is editable, with the snap default', function (): void {
    $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))
        ->assertOk()
        ->assertJsonPath('data.editor.can_edit', true)
        ->assertJsonPath('data.editor.snap_to_grid', true);
});

it('offers no editor when the room has no active layout', function (): void {
    $this->layout->update(['is_active' => false]);

    $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))
        ->assertOk()
        ->assertJsonPath('data.editor.can_edit', false);
});

it('counts live PC units that have no position yet', function (): void {
    PcUnit::factory()->count(2)->create(['room_id' => $this->room->id]);
    PcUnit::factory()->create(['room_id' => $this->room->id])->delete();

    $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))
        ->assertOk()
        ->assertJsonCount(1, 'data.pcs')
        ->assertJsonPath('data.unplaced_count', 2);
});

// ── What the payload says ───────────────────────────────────────────────────

it('carries the server\'s status label and tone, from PcStatus', function (): void {
    foreach (PcStatus::cases() as $i => $status) {
        $pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'status' => $status->value]);
        FloorPlanPosition::factory()->create([
            'room_layout_id' => $this->layout->id, 'pc_unit_id' => $pc->id, 'pos_x' => 20 * ($i + 1), 'pos_y' => 20,
        ]);
    }

    $pcs = collect($this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))->assertOk()->json('data.pcs'));

    foreach (PcStatus::cases() as $status) {
        $match = $pcs->firstWhere('status.value', $status->value);

        expect($match['status'])->toBe([
            'value' => $status->value, 'label' => $status->label(), 'tone' => $status->tone(),
        ]);
    }
});

it('exposes a narrow payload with no numeric ids and no register data', function (): void {
    $response = $this->actingAs(userWithRole('administrator'))->getJson(($this->url)($this->room->uuid))->assertOk();
    $data = $response->json('data');

    expect(array_keys($data))->toBe(['room', 'layout', 'pcs', 'unplaced', 'unplaced_count', 'editor'])
        ->and(array_keys($data['room']))->toBe(['id', 'name', 'code', 'floor', 'building'])
        ->and(array_keys($data['layout']))->toBe(['version', 'width', 'height', 'grid_size'])
        ->and(array_keys($data['pcs'][0]))->toBe(['id', 'name', 'unit_code', 'status', 'x', 'y', 'rotation', 'z_index'])
        ->and(array_keys($data['editor']))->toBe(['can_edit', 'snap_to_grid']);

    $encoded = (string) $response->getContent();

    foreach (['serial_number', 'mac_address', 'ip_address', 'asset_tag', 'hostname', 'qr_identifier', 'room_id', 'pc_unit_id'] as $field) {
        expect($encoded)->not->toContain($field);
    }
    expect($encoded)->not->toContain($this->pc->serial_number);
});
