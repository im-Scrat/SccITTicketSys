<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\PermissionGrantType;
use App\Models\ActivityLog;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WP-F — layout creation and activation.
 *
 * `RoomLayoutPolicy::manage` and `RoomLayoutService::authorizeManage` are
 * already proven in `RoomLayoutServiceTest`/`FloorPlanPlacementTest`; this
 * file is about what the two write endpoints actually do to the
 * `room_layouts` table, and repeats the authorization/IDOR floor once per
 * endpoint because a route wiring mistake would not show up in a service-level
 * test.
 */
beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->admin = userWithRole('administrator');
    $this->room = Room::factory()->create();
    $this->createUrl = fn (?Room $room = null): string => sprintf(
        '/api/admin/floor-plan/rooms/%s/layouts',
        ($room ?? $this->room)->uuid,
    );
    $this->activateUrl = fn (int $version, ?Room $room = null): string => sprintf(
        '/api/admin/floor-plan/rooms/%s/layouts/%d/activate',
        ($room ?? $this->room)->uuid,
        $version,
    );
});

function grantLayoutPerUser(User $user, PermissionGrantType $type = PermissionGrantType::Grant): void
{
    foreach (['floorplan.view', 'floorplan.manage'] as $name) {
        DB::table('user_permissions')->insert([
            'user_id' => $user->getKey(),
            'permission_id' => Permission::query()->where('name', $name)->value('id'),
            'grant_type' => $type->value,
        ]);
    }

    app(PermissionResolver::class)->forget($user);
}

// ── Creation ─────────────────────────────────────────────────────────────────

it('creates version 1, inactive, for a room with no layout yet', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])
        ->assertCreated();

    $response->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.width', 800)
        ->assertJsonPath('data.height', 600)
        ->assertJsonPath('data.is_active', false);

    expect(RoomLayout::query()->where('room_id', $this->room->id)->count())->toBe(1);
});

it('defaults grid_size from the floor_plan.default_grid_size setting', function (): void {
    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])
        ->assertCreated()
        ->assertJsonPath('data.grid_size', 20);
});

it('accepts an explicit grid_size', function (): void {
    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600, 'grid_size' => 40])
        ->assertCreated()
        ->assertJsonPath('data.grid_size', 40);
});

it('numbers a second version one past the highest existing version, never reusing one', function (): void {
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 3, 'is_active' => false]);

    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])
        ->assertCreated()
        ->assertJsonPath('data.version', 4);

    // The existing active layout is untouched by creating a new, inactive one.
    expect(RoomLayout::query()->where('room_id', $this->room->id)->where('version', 1)->value('is_active'))
        ->toBeTrue();
});

it('never activates a layout on creation, even for a room with no other layout', function (): void {
    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])
        ->assertJsonPath('data.is_active', false);

    expect(RoomLayout::query()->where('room_id', $this->room->id)->where('is_active', true)->exists())
        ->toBeFalse();
});

it('rejects non-positive or missing dimensions with 422', function (array $payload, string $field): void {
    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'zero width' => [['width' => 0, 'height' => 600], 'width'],
    'negative height' => [['width' => 800, 'height' => -1], 'height'],
    'missing width' => [['height' => 600], 'width'],
    'zero grid_size' => [['width' => 800, 'height' => 600, 'grid_size' => 0], 'grid_size'],
]);

it('records LayoutCreated on the layout itself, not the room', function (): void {
    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])
        ->assertCreated();

    $layout = RoomLayout::query()->where('room_id', $this->room->id)->firstOrFail();

    $log = ActivityLog::query()
        ->where('action', ActivityAction::LayoutCreated->value)
        ->where('subject_type', $layout->getMorphClass())
        ->where('subject_id', $layout->getKey())
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->properties)->toMatchArray(['version' => 1, 'width' => 800, 'height' => 600]);
});

it('refuses a Technician and a Teacher, identically for a real and an invented room', function (string $role): void {
    $actor = userWithRole($role);
    $unknownUrl = sprintf('/api/admin/floor-plan/rooms/%s/layouts', (string) Str::uuid());

    $this->actingAs($actor)->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])->assertForbidden();
    $this->actingAs($actor)->postJson($unknownUrl, ['width' => 800, 'height' => 600])->assertForbidden();
})->with(['technician', 'teacher']);

it('still refuses a Technician or Teacher individually granted floorplan.view and floorplan.manage', function (string $role): void {
    $actor = userWithRole($role);
    grantLayoutPerUser($actor);

    $this->actingAs($actor)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])
        ->assertForbidden();

    expect(RoomLayout::query()->where('room_id', $this->room->id)->exists())->toBeFalse();
})->with(['technician', 'teacher']);

it('refuses an Administrator whose floorplan.manage is denied', function (): void {
    grantLayoutPerUser($this->admin, PermissionGrantType::Deny);

    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)(), ['width' => 800, 'height' => 600])
        ->assertForbidden();
});

it('answers an unknown or archived room with 403 for a non-administrator and 404 for an Administrator', function (): void {
    $archived = Room::factory()->create();
    $archived->delete();

    $this->actingAs(userWithRole('teacher'))
        ->postJson(($this->createUrl)($archived), ['width' => 800, 'height' => 600])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->postJson(($this->createUrl)($archived), ['width' => 800, 'height' => 600])
        ->assertNotFound();
});

// ── Activation ───────────────────────────────────────────────────────────────

it('activates a version and deactivates whichever was active before it', function (): void {
    $v1 = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);
    $v2 = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'is_active' => false]);

    $this->actingAs($this->admin)
        ->postJson(($this->activateUrl)(2))
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.is_active', true);

    expect($v1->fresh()->is_active)->toBeFalse()
        ->and($v2->fresh()->is_active)->toBeTrue()
        ->and(RoomLayout::query()->where('room_id', $this->room->id)->where('is_active', true)->count())->toBe(1);
});

it('activating the already-active layout is a no-op, not an error', function (): void {
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->postJson(($this->activateUrl)(1))
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect(RoomLayout::query()->where('room_id', $this->room->id)->where('is_active', true)->count())->toBe(1);
});

it('activating a room with no prior active layout still leaves exactly one active afterward', function (): void {
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => false]);

    $this->actingAs($this->admin)->postJson(($this->activateUrl)(1))->assertOk();

    expect(RoomLayout::query()->where('room_id', $this->room->id)->where('is_active', true)->count())->toBe(1);
});

it('leaves position history on the deactivated layout untouched — it becomes read-only, not deleted', function (): void {
    $v1 = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);
    $pc = PcUnit::factory()->create(['room_id' => $this->room->id]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $v1->id, 'pc_unit_id' => $pc->id, 'pos_x' => 40, 'pos_y' => 40]);
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'is_active' => false]);

    $this->actingAs($this->admin)->postJson(($this->activateUrl)(2))->assertOk();

    expect(FloorPlanPosition::query()->where('room_layout_id', $v1->id)->count())->toBe(1);

    // The now-historical version is refused for a write (WP-B/D behaviour, unchanged).
    $this->actingAs($this->admin)
        ->patchJson("/api/admin/floor-plan/rooms/{$this->room->uuid}/layouts/1/positions/{$pc->uuid}", ['x' => 10, 'y' => 10])
        ->assertStatus(409)
        ->assertJsonPath('code', 'layout_not_active');
});

it('records LayoutActivated', function (): void {
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);
    $v2 = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'is_active' => false]);

    $this->actingAs($this->admin)->postJson(($this->activateUrl)(2))->assertOk();

    expect(
        ActivityLog::query()
            ->where('action', ActivityAction::LayoutActivated->value)
            ->where('subject_type', $v2->getMorphClass())
            ->where('subject_id', $v2->getKey())
            ->exists(),
    )->toBeTrue();
});

it('answers a version the room does not have with 404', function (): void {
    $this->actingAs($this->admin)->postJson(($this->activateUrl)(9))->assertNotFound();
});

it('never activates another room\'s version through this room', function (): void {
    $other = Room::factory()->create();
    RoomLayout::factory()->create(['room_id' => $other->id, 'version' => 5, 'is_active' => false]);

    $this->actingAs($this->admin)->postJson(($this->activateUrl)(5))->assertNotFound();

    expect(RoomLayout::query()->where('room_id', $other->id)->where('version', 5)->value('is_active'))->toBeFalse();
});

it('refuses a Technician and a Teacher, identically for a real and an unknown version', function (string $role): void {
    RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);
    $actor = userWithRole($role);

    $this->actingAs($actor)->postJson(($this->activateUrl)(1))->assertForbidden();
    $this->actingAs($actor)->postJson(($this->activateUrl)(99))->assertForbidden();
})->with(['technician', 'teacher']);

it('answers an archived room with 403 for a non-administrator and 404 for an Administrator on activate', function (): void {
    $archived = Room::factory()->create();
    RoomLayout::factory()->create(['room_id' => $archived->id, 'version' => 1, 'is_active' => true]);
    $archived->delete();

    $this->actingAs(userWithRole('teacher'))->postJson(($this->activateUrl)(1, $archived))->assertForbidden();
    $this->actingAs($this->admin)->postJson(($this->activateUrl)(1, $archived))->assertNotFound();
});
