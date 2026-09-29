<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\PermissionGrantType;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WP-E — who may authorize `private-floor-plan.room.{roomUuid}`.
 *
 * Tests the **actual registered channel** in `routes/channels.php` (unlike
 * WP-A's infrastructure test, which registers its own throwaway channel to
 * test the auth route independently of any feature). This file proves the
 * floor-plan channel rule itself enforces the same Administrator-only
 * boundary as the read endpoint (`FloorPlanRoomEndpointTest`) and the write
 * endpoint (`FloorPlanPlacementTest`) — the same three surfaces, one rule.
 */
const REVERB_KEY = 'test-fp-reverb-key';
const REVERB_SECRET = 'test-fp-reverb-secret-never-sent';
const SOCKET_ID = '4242.1337';

beforeEach(function (): void {
    seedRbac();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => REVERB_KEY,
        'broadcasting.connections.reverb.secret' => REVERB_SECRET,
        'broadcasting.connections.reverb.app_id' => '999999',
        'broadcasting.connections.reverb.options' => [
            'host' => 'reverb', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();

    // `routes/channels.php` registers its channel on the broadcaster that was
    // the default *when the app booted* (BROADCAST_CONNECTION=null in
    // phpunit.xml) — the same reason WP-A's own broadcast test re-registers
    // its channel after forgetDrivers(). Re-requiring the real file (not a
    // copy of its logic) re-attaches the actual `floor-plan.room.{roomUuid}`
    // rule to the 'reverb' driver just configured above, so what this file
    // tests is the real routes/channels.php, not a stand-in for it.
    require base_path('routes/channels.php');

    $this->roomUuid = (string) Str::uuid();
    $this->authorize = fn (?string $roomUuid = null) => test()->postJson('/broadcasting/auth', [
        'socket_id' => SOCKET_ID,
        'channel_name' => 'private-floor-plan.room.'.($roomUuid ?? $this->roomUuid),
    ]);
});

function grantFloorPlanPerUser(User $user, PermissionGrantType $type = PermissionGrantType::Grant): void
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

it('refuses an unauthenticated client', function (): void {
    ($this->authorize)()->assertUnauthorized();
});

it('authorizes an Administrator, signed with the configured secret', function (): void {
    $this->actingAs(userWithRole('administrator'));

    $response = ($this->authorize)()->assertOk();

    $expected = hash_hmac(
        'sha256',
        SOCKET_ID.':private-floor-plan.room.'.$this->roomUuid,
        REVERB_SECRET,
    );
    expect($response->json('auth'))->toBe(REVERB_KEY.':'.$expected);
    expect($response->getContent())->not->toContain(REVERB_SECRET);
});

it('refuses a Technician and a Teacher, for a real-looking room uuid', function (string $role): void {
    $this->actingAs(userWithRole($role));

    ($this->authorize)()->assertForbidden();
})->with(['technician', 'teacher']);

it('refuses identically for an invented room uuid — no room-existence oracle', function (string $role): void {
    $this->actingAs(userWithRole($role));

    ($this->authorize)((string) Str::uuid())->assertForbidden();
})->with(['technician', 'teacher']);

it('still refuses a Technician or Teacher individually granted floorplan.view and floorplan.manage', function (string $role): void {
    $actor = userWithRole($role);
    grantFloorPlanPerUser($actor);

    expect($actor->hasPermissionTo('floorplan.manage'))->toBeTrue();

    $this->actingAs($actor);
    ($this->authorize)()->assertForbidden();
})->with(['technician', 'teacher']);

it('honours a per-user deny on an Administrator', function (): void {
    $admin = userWithRole('administrator');
    grantFloorPlanPerUser($admin, PermissionGrantType::Deny);

    $this->actingAs($admin);
    ($this->authorize)()->assertForbidden();
});
