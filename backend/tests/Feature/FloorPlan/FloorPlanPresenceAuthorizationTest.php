<?php

declare(strict_types=1);

use App\Enums\PermissionGrantType;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/**
 * Phase 2.11 — who may join `presence-floor-plan.editors.{roomUuid}`, and what
 * the other members learn about them (SDD DD-103).
 *
 * Tests the real registered channel in `routes/channels.php`, re-required onto
 * the configured Reverb driver exactly as `FloorPlanBroadcastAuthorizationTest`
 * does. The member payload is asserted against the **encoded** response: it
 * must carry a uuid and a display name — never the integer id or an email.
 */
const PRESENCE_REVERB_KEY = 'test-presence-reverb-key';
const PRESENCE_REVERB_SECRET = 'test-presence-reverb-secret-never-sent';
const PRESENCE_SOCKET_ID = '5151.2024';

beforeEach(function (): void {
    seedRbac();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => PRESENCE_REVERB_KEY,
        'broadcasting.connections.reverb.secret' => PRESENCE_REVERB_SECRET,
        'broadcasting.connections.reverb.app_id' => '999998',
        'broadcasting.connections.reverb.options' => [
            'host' => 'reverb', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false,
        ],
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');

    $this->roomUuid = (string) Str::uuid();
    $this->join = fn (?string $roomUuid = null) => test()->postJson('/broadcasting/auth', [
        'socket_id' => PRESENCE_SOCKET_ID,
        'channel_name' => 'presence-floor-plan.editors.'.($roomUuid ?? $this->roomUuid),
    ]);
});

it('admits an Administrator and introduces them by uuid and name only', function (): void {
    $admin = userWithRole('administrator');
    $this->actingAs($admin);

    $response = ($this->join)()->assertOk();
    $member = json_decode((string) $response->json('channel_data'), true, flags: JSON_THROW_ON_ERROR);

    expect($member)->toBe([
        'user_id' => $admin->uuid,
        'user_info' => ['id' => $admin->uuid, 'name' => $admin->fullName()],
    ]);

    $encoded = (string) $response->getContent();
    expect($encoded)->not->toContain($admin->email)
        ->and($encoded)->not->toContain('"user_id":'.$admin->id)
        ->and($encoded)->not->toContain(PRESENCE_REVERB_SECRET);
});

it('refuses an unauthenticated client', function (): void {
    ($this->join)()->assertUnauthorized();
});

it('refuses a Technician and a Teacher, identically for an invented room', function (string $role): void {
    $this->actingAs(userWithRole($role));

    ($this->join)()->assertForbidden();
    ($this->join)((string) Str::uuid())->assertForbidden();
})->with(['technician', 'teacher']);

it('still refuses a Technician individually granted floorplan.view — the Gate::before trap', function (): void {
    $technician = userWithRole('technician');
    givePermission($technician, 'floorplan.view');
    givePermission($technician, 'floorplan.manage');
    $this->actingAs($technician);

    ($this->join)()->assertForbidden();
});

it('honours a per-user deny on an Administrator', function (): void {
    $admin = userWithRole('administrator');
    givePermission($admin, 'floorplan.view', PermissionGrantType::Deny);
    $this->actingAs($admin);

    ($this->join)()->assertForbidden();
});
