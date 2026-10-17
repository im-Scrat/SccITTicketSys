<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\PermissionGrantType;
use App\Enums\UserStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * The room-scoped equipment lookup's authorization file (SDD DD-78) — the
 * report form's "which item has the problem?" step, `GET /api/lookups/equipment`.
 *
 * What it must hold, role by role:
 *  - it answers only callers who may name **both** a PC and an asset, because it
 *    returns both lists;
 *  - a room the location lookup would not offer answers exactly like a room
 *    that does not exist — two empty lists, never a 404 — and the same room
 *    is refused when a ticket names it directly (the list and the by-uuid
 *    check agree, or the list filter is an IDOR);
 *  - the rows are labels only, asserted on the encoded payload so a later
 *    resource change cannot leak a field back in;
 *  - it is throttled per account.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);
    Queue::fake();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->room = Room::factory()->create(['name' => 'Lab A']);
    $this->pc = PcUnit::factory()->create([
        'room_id' => $this->room->id,
        'pc_name' => 'PC 1',
        'unit_code' => 'LABA-01',
        'serial_number' => 'SERIALPCSECRET',
        'hostname' => 'hostsecret-pc',
    ]);
    $this->printer = assetOfType(ComponentType::Printer, [
        'current_room_id' => $this->room->id,
        'status' => AssetStatus::Deployed->value,
        'asset_tag' => 'PRN-0001',
        'serial_number' => 'SERIALASSETSECRET',
        'purchase_price' => 1999.99,
    ]);
});

/** @param array<string, mixed> $query */
function equipmentLookupUrl(Room|string $room, array $query = []): string
{
    return '/api/lookups/equipment?'.http_build_query([
        'room' => $room instanceof Room ? $room->uuid : $room,
        ...$query,
    ]);
}

/* ------------------------------------------------------------- who may ask */

it('requires authentication', function () {
    $this->getJson(equipmentLookupUrl($this->room))->assertUnauthorized();
});

it('answers an administrator, a technician and a teacher with both lists', function () {
    foreach (['administrator' => $this->admin, 'technician' => $this->technician, 'teacher' => $this->teacher] as $who => $user) {
        $response = $this->actingAs($user)->getJson(equipmentLookupUrl($this->room))->assertOk();

        expect($response->json('data.pc_units.0.id'))->toBe($this->pc->uuid, $who)
            ->and($response->json('data.assets.0.id'))->toBe($this->printer->uuid, $who);
    }
});

it('refuses a teacher whose ticket permissions are withdrawn', function () {
    givePermission($this->teacher, 'tickets.view', PermissionGrantType::Deny);
    givePermission($this->teacher, 'tickets.create', PermissionGrantType::Deny);

    expect(Gate::forUser($this->teacher)->allows('selectPcUnit', PcUnit::class))->toBeFalse()
        ->and(Gate::forUser($this->teacher)->allows('selectAsset', Asset::class))->toBeFalse();

    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($this->room))->assertForbidden();
});

it('refuses an account that may name a PC but not another asset', function () {
    // Floor-plan access opens the PC lookup only. This endpoint answers with
    // both lists, so half the abilities is no access — not half a list the
    // client would then mistake for the whole room.
    givePermission($this->teacher, 'tickets.view', PermissionGrantType::Deny);
    givePermission($this->teacher, 'tickets.create', PermissionGrantType::Deny);
    givePermission($this->teacher, 'floorplan.view');

    expect(Gate::forUser($this->teacher)->allows('selectPcUnit', PcUnit::class))->toBeTrue()
        ->and(Gate::forUser($this->teacher)->allows('selectAsset', Asset::class))->toBeFalse();

    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($this->room))->assertForbidden();
});

it('refuses a suspended account', function () {
    $suspended = userWithRole('teacher', ['status' => UserStatus::Suspended->value]);

    $this->actingAs($suspended)->getJson(equipmentLookupUrl($this->room))->assertForbidden();
});

it('refuses an account that must reset its password first', function () {
    $pending = userWithRole('teacher', ['force_password_reset' => true]);

    $this->actingAs($pending)->getJson(equipmentLookupUrl($this->room))
        ->assertForbidden()
        ->assertJsonPath('code', 'password_reset_required');
});

/* ---------------------------------------------------------------- the input */

it('requires a well-formed room and caps the limit', function () {
    $this->actingAs($this->teacher)->getJson('/api/lookups/equipment')
        ->assertUnprocessable()->assertJsonValidationErrors('room');

    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl('not-a-uuid'))
        ->assertUnprocessable()->assertJsonValidationErrors('room');

    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($this->room, ['limit' => 201]))
        ->assertUnprocessable()->assertJsonValidationErrors('limit');
});

it('throttles each account', function () {
    expect(config('security.rate_limits.lookup_equipment_per_minute'))->toBe(120);

    config(['security.rate_limits.lookup_equipment_per_minute' => 2]);

    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($this->room))->assertOk();
    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($this->room))->assertOk();
    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($this->room))->assertTooManyRequests();

    // Per account, not per address: a colleague behind the same school NAT
    // still has their own budget.
    $this->actingAs(userWithRole('teacher'))->getJson(equipmentLookupUrl($this->room))->assertOk();
});

/* ------------------------------------------------- no existence oracle, IDOR */

it('answers an inactive room exactly as it answers a room that does not exist', function () {
    $inactive = Room::factory()->create(['is_active' => false]);
    PcUnit::factory()->create(['room_id' => $inactive->id]);
    assetOfType(ComponentType::Printer, ['current_room_id' => $inactive->id, 'status' => AssetStatus::Deployed->value]);

    $rooms = collect($this->actingAs($this->teacher)->getJson('/api/lookups/rooms?limit=200')->json('data'))->pluck('id');
    expect($rooms)->not->toContain($inactive->uuid);

    $empty = ['data' => ['pc_units' => [], 'assets' => []]];

    expect($this->actingAs($this->teacher)->getJson(equipmentLookupUrl($inactive))->assertOk()->json())->toBe($empty)
        ->and($this->actingAs($this->teacher)->getJson(equipmentLookupUrl((string) Str::uuid()))->assertOk()->json())->toBe($empty);
});

it('answers a room in an inactive building with two empty lists', function () {
    $building = Building::factory()->create(['is_active' => false]);
    $room = Room::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $building->id])->id]);
    PcUnit::factory()->create(['room_id' => $room->id]);
    assetOfType(ComponentType::Projector, ['current_room_id' => $room->id, 'status' => AssetStatus::Deployed->value]);

    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($room))
        ->assertOk()
        ->assertExactJson(['data' => ['pc_units' => [], 'assets' => []]]);
});

it('answers an archived room with two empty lists', function () {
    $room = Room::factory()->create();
    PcUnit::factory()->create(['room_id' => $room->id]);
    assetOfType(ComponentType::Monitor, ['current_room_id' => $room->id, 'status' => AssetStatus::Deployed->value]);
    $room->delete();

    $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($room))
        ->assertOk()
        ->assertExactJson(['data' => ['pc_units' => [], 'assets' => []]]);
});

it('refuses a ticket filed against the room the lookup hides', function () {
    $inactive = Room::factory()->create(['is_active' => false]);

    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Projector will not power on',
        'description' => 'The projector shows no light at all when switched on.',
        'category' => 'hardware',
        'room' => $inactive->uuid,
    ])->assertUnprocessable()->assertJsonValidationErrors('room');
});

/* --------------------------------------------------------------- label only */

it('returns labels only, never operational data', function () {
    $response = $this->actingAs($this->teacher)->getJson(equipmentLookupUrl($this->room))->assertOk();

    foreach (['pc_units', 'assets'] as $list) {
        expect(array_keys($response->json("data.{$list}.0")))
            ->toEqualCanonicalizing(['id', 'label', 'identifier', 'location'], $list);
    }

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain('1999.99')
        ->and($encoded)->not->toContain('purchase')
        ->and($encoded)->not->toContain('status')
        ->and($encoded)->not->toContain('supplier')
        ->and($encoded)->not->toContain('serial')
        ->and($encoded)->not->toContain('SERIALPCSECRET')
        ->and($encoded)->not->toContain('SERIALASSETSECRET')
        ->and($encoded)->not->toContain('hostsecret');
});
