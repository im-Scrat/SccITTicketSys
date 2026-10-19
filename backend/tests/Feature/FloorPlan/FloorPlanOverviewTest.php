<?php

declare(strict_types=1);

use App\Enums\PcStatus;
use App\Enums\PermissionGrantType;
use App\Models\Building;
use App\Models\FloorPlanFixture;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use Illuminate\Support\Str;

/**
 * Phase 2.11 — `GET /api/admin/floor-plan/rooms`, the Floorplan landing page's
 * room overview. Administrator-only by policy ability; counts and preview are
 * trusted only for machines still live in the room; the encoded payload names
 * people and rooms by uuid and display name only.
 */
beforeEach(function (): void {
    seedRbac();
    $this->admin = userWithRole('administrator');

    $this->lab = Room::factory()->create(['name' => 'Alpha Lab', 'code' => 'ALPHA']);
    $this->office = Room::factory()->create(['name' => 'Beta Office', 'code' => 'BETA']);

    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->lab->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'is_active' => true, 'revision' => 4,
        'updated_by' => $this->admin->id,
    ]);
    $this->placed = PcUnit::factory()->create(['room_id' => $this->lab->id, 'status' => PcStatus::Available->value]);
    $this->broken = PcUnit::factory()->create(['room_id' => $this->lab->id, 'status' => PcStatus::NeedsFix->value]);
    PcUnit::factory()->create(['room_id' => $this->lab->id, 'status' => PcStatus::PendingRepair->value]);
    FloorPlanPosition::factory()->create(['room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->placed->id, 'pos_x' => 100.4, 'pos_y' => 80, 'rotation' => 90]);
    FloorPlanFixture::query()->create([
        'uuid' => (string) Str::uuid(), 'room_layout_id' => $this->layout->id, 'kind' => 'desk',
        'pos_x' => 100, 'pos_y' => 80, 'width' => 80, 'height' => 40, 'rotation' => 0,
    ]);
    PcUnit::factory()->create(['room_id' => null]);

    $this->card = fn (array $cards, Room $room): ?array => collect($cards)->firstWhere('id', $room->uuid);
});

it('lists every live room with its machines\' repair status and its Floorplan state', function (): void {
    $cards = $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms')->assertOk()->json('data');
    $lab = ($this->card)($cards, $this->lab);

    expect($lab['pcs']['total'])->toBe(3)
        ->and($lab['pcs']['placed'])->toBe(1)
        ->and(collect($lab['pcs']['by_status'])->pluck('count', 'value')->all())->toBe(['available' => 1, 'pending_repair' => 1, 'needs_fix' => 1])
        ->and($lab['layout']['revision'])->toBe(4)
        ->and($lab['layout']['updated_by'])->toBe(['name' => $this->admin->fullName()])
        ->and(($this->card)($cards, $this->office)['layout'])->toBeNull();
});

it('draws a small, integer-rounded preview of the layout', function (): void {
    $lab = ($this->card)($this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms')->json('data'), $this->lab);

    expect($lab['preview']['pcs'])->toBe([[100, 80, 90, 'available']])
        ->and($lab['preview']['fixtures'])->toBe([['desk', 100, 80, 80, 40, 0]])
        ->and($lab['preview']['truncated'])->toBeFalse();
});

it('neither counts nor draws a machine that has left the room', function (): void {
    $this->placed->update(['room_id' => $this->office->id]);

    $lab = ($this->card)($this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms')->json('data'), $this->lab);

    expect($lab['pcs']['placed'])->toBe(0)->and($lab['preview']['pcs'])->toBe([]);
});

it('reports how many PCs have no room at all', function (): void {
    $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms')->assertOk()->assertJsonPath('meta.unassigned_pcs', 1);
});

it('leaves out archived rooms', function (): void {
    $this->office->delete();

    $cards = $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms')->json('data');

    expect(($this->card)($cards, $this->office))->toBeNull();
});

it('filters by search, by building and by whether a Floorplan exists', function (): void {
    $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms?search=beta')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->office->uuid);
    $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms?layout=set_up')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->lab->uuid);
    $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms?layout=not_set_up')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->office->uuid);

    $building = $this->lab->floor->building;
    $this->actingAs($this->admin)
        ->getJson("/api/admin/floor-plan/rooms?building={$building->uuid}")
        ->assertJsonPath('data.0.id', $this->lab->uuid);
    $this->actingAs($this->admin)
        ->getJson('/api/admin/floor-plan/rooms?building='.Building::factory()->create()->uuid)
        ->assertJsonCount(0, 'data');
});

it('puts the rooms that need attention first when asked', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/floor-plan/rooms?sort=attention')
        ->assertJsonPath('data.0.id', $this->lab->uuid);
});

it('exposes no internal ids, no email and no asset-register details', function (): void {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms')->assertOk();
    $encoded = (string) $response->getContent();

    foreach (['room_id', 'pc_unit_id', 'serial_number', 'mac_address', 'ip_address', 'hostname', 'asset_tag', $this->admin->email] as $leak) {
        expect($encoded)->not->toContain($leak);
    }
    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'code', 'room_type', 'is_active', 'capacity', 'floor', 'building', 'pcs', 'layout', 'preview']);
});

it('refuses every non-administrator role', function (string $role): void {
    $this->actingAs(userWithRole($role))->getJson('/api/admin/floor-plan/rooms')->assertForbidden();
})->with(['technician', 'teacher']);

it('refuses a technician granted floorplan.view per user — the Gate::before trap', function (): void {
    $technician = userWithRole('technician');
    givePermission($technician, 'floorplan.view');

    $this->actingAs($technician)->getJson('/api/admin/floor-plan/rooms')->assertForbidden();
});

it('refuses an administrator whose floorplan.view is denied', function (): void {
    givePermission($this->admin, 'floorplan.view', PermissionGrantType::Deny);

    $this->actingAs($this->admin)->getJson('/api/admin/floor-plan/rooms')->assertForbidden();
});

it('refuses a guest', function (): void {
    $this->getJson('/api/admin/floor-plan/rooms')->assertUnauthorized();
});
