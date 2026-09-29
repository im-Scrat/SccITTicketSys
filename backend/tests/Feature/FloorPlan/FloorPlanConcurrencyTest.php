<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\PcStatus;
use App\Models\ActivityLog;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;

/**
 * WP-F — optimistic concurrency (D3) and position history.
 *
 * "Two clients" below is two sequential requests against the same server
 * state, exactly as the real conflict happens: both administrators loaded the
 * plan, hold the same `updated_at`, and the one whose write lands second is
 * the one who is now wrong. Pest cannot open two browsers, but it can and
 * does exercise the identical server code path a real second browser would
 * hit — the same `PlacePcUnit::assertNotStale` this file is proving.
 *
 * Every request below sends `snap: false` and only grid-aligned coordinates:
 * snapping itself is `CoordinateService`'s and `FloorPlanPlacementTest`'s
 * concern, and letting it run here would make every expected `x`/`y` a
 * function of the grid instead of the literal sent.
 *
 * `activity_logs.properties` is `jsonb`: Postgres does not preserve key
 * insertion order on read-back, and a value written as a PHP float that has
 * no fractional part round-trips through JSON as a bare integer (no
 * `JSON_PRESERVE_ZERO_FRACTION`). Assertions below use `toEqual` (order-
 * insensitive) with plain integers, matching what is actually stored and
 * genuinely read back — not what the PHP call site happened to type.
 */
beforeEach(function (): void {
    seedRbac();
    $this->withHeader('Origin', (string) config('app.url'));

    $this->admin = userWithRole('administrator');
    $this->room = Room::factory()->create();
    $this->layout = RoomLayout::factory()->create([
        'room_id' => $this->room->id, 'version' => 1, 'width' => 1000, 'height' => 600, 'grid_size' => 20, 'is_active' => true,
    ]);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC-01', 'status' => PcStatus::Online->value]);
    $this->position = FloorPlanPosition::factory()->create([
        'room_layout_id' => $this->layout->id, 'pc_unit_id' => $this->pc->id, 'pos_x' => 100, 'pos_y' => 100,
    ]);

    $this->url = fn (): string => sprintf(
        '/api/admin/floor-plan/rooms/%s/layouts/1/positions/%s',
        $this->room->uuid,
        $this->pc->uuid,
    );
    // `expected_updated_at: null` and omitting the key are equally "nothing to
    // echo" to a `sometimes|nullable` rule, so sending null here for the
    // common "don't care" case is just simpler than conditionally building
    // the array per call.
    $this->move = fn (float $x, float $y, ?string $expectedUpdatedAt = null) => test()->patchJson(
        ($this->url)(),
        ['x' => $x, 'y' => $y, 'snap' => false, 'expected_updated_at' => $expectedUpdatedAt],
    );
    // What a real client actually holds: the exact string the read endpoint
    // serialized, fetched the same way — never the raw DB column read
    // directly, which loses the precision `FloorPlanPcResource` encodes
    // (Carbon's default (string) cast has no microseconds regardless of the
    // column's actual precision).
    $this->currentUpdatedAt = fn (): string => test()
        ->getJson("/api/admin/floor-plan/rooms/{$this->room->uuid}")
        ->json('data.pcs.0.updated_at');
});

it('accepts a move whose expected_updated_at matches what the client actually last read', function (): void {
    $this->actingAs($this->admin);
    $seenByClient = ($this->currentUpdatedAt)();

    ($this->move)(200, 200, $seenByClient)
        ->assertOk()
        ->assertJsonPath('data.x', 200)
        ->assertJsonPath('data.y', 200);
});

it('the two-client race: client A moves the unit; client B, holding the pre-A timestamp, is refused with 409', function (): void {
    $this->actingAs($this->admin);

    // Both clients loaded the plan at this instant.
    $bothClientsSaw = ($this->currentUpdatedAt)();

    // Client A moves first, successfully.
    ($this->move)(300, 300, $bothClientsSaw)->assertOk()->assertJsonPath('data.x', 300);

    // Client B, still holding the value from before A's move, tries next.
    $response = ($this->move)(500, 500, $bothClientsSaw)
        ->assertStatus(409)
        ->assertJsonPath('code', 'position_stale');

    // Client B's refusal carries the *current* state (A's move) to reconcile to.
    $response->assertJsonPath('current.id', $this->pc->uuid)
        ->assertJsonPath('current.x', 300)
        ->assertJsonPath('current.y', 300);

    expect($response->json('current.updated_at'))->not->toBe($bothClientsSaw);

    // B's write never happened.
    $row = FloorPlanPosition::query()->whereKey($this->position->id)->first();
    expect($row->pos_x)->toEqual('300.00')
        ->and($row->pos_y)->toEqual('300.00');
});

it('client B can retry immediately using the current value the 409 just gave it, and it succeeds', function (): void {
    $this->actingAs($this->admin);
    $bothClientsSaw = ($this->currentUpdatedAt)();

    ($this->move)(300, 300, $bothClientsSaw)->assertOk();

    $refusal = ($this->move)(500, 500, $bothClientsSaw)->assertStatus(409);

    ($this->move)(500, 500, $refusal->json('current.updated_at'))
        ->assertOk()
        ->assertJsonPath('data.x', 500);
});

it('does not require expected_updated_at at all: an omitted token is last-write-wins', function (): void {
    $this->actingAs($this->admin);

    ($this->move)(240, 240)->assertOk()->assertJsonPath('data.x', 240);
});

it('never checks staleness for a first placement — nothing was ever read', function (): void {
    $fresh = PcUnit::factory()->create(['room_id' => $this->room->id]);

    $this->actingAs($this->admin)
        ->patchJson(
            "/api/admin/floor-plan/rooms/{$this->room->uuid}/layouts/1/positions/{$fresh->uuid}",
            ['x' => 20, 'y' => 20, 'snap' => false, 'expected_updated_at' => now()->subDay()->toIso8601String()],
        )
        ->assertCreated();
});

it('rejects a non-date expected_updated_at with 422', function (): void {
    $this->actingAs($this->admin)
        ->patchJson(($this->url)(), ['x' => 200, 'y' => 200, 'expected_updated_at' => 'not-a-date'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_updated_at');
});

it('every response carries updated_at, so a client always has a token for its next move', function (): void {
    $this->actingAs($this->admin);
    $response = ($this->move)(200, 200)->assertOk();

    expect($response->json('data.updated_at'))->toBeString()->not->toBeEmpty();
});

// ── Position history (SRS FR-FP-008; DD-55 — activity_logs, no new table) ──

it('records an AssetPositionChanged row on the PC unit, with from/to and the layout version', function (): void {
    $this->actingAs($this->admin);
    ($this->move)(400, 260)->assertOk();

    $log = ActivityLog::query()
        ->where('action', ActivityAction::AssetPositionChanged->value)
        ->where('subject_type', $this->pc->getMorphClass())
        ->where('subject_id', $this->pc->getKey())
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->module)->toBe('floor-plan')
        ->and($log->properties)->toEqual([
            'from' => ['x' => 100, 'y' => 100],
            'to' => ['x' => 400, 'y' => 260],
            'layout_version' => 1,
        ]);
});

it('records a null "from" on a unit\'s first placement', function (): void {
    $fresh = PcUnit::factory()->create(['room_id' => $this->room->id]);

    $this->actingAs($this->admin)
        ->patchJson(
            "/api/admin/floor-plan/rooms/{$this->room->uuid}/layouts/1/positions/{$fresh->uuid}",
            ['x' => 40, 'y' => 40, 'snap' => false],
        )
        ->assertCreated();

    $log = ActivityLog::query()
        ->where('action', ActivityAction::AssetPositionChanged->value)
        ->where('subject_id', $fresh->getKey())
        ->where('subject_type', $fresh->getMorphClass())
        ->first();

    expect($log->properties['from'])->toBeNull()
        ->and($log->properties['to'])->toEqual(['x' => 40, 'y' => 40]);
});

it('accumulates one history row per accepted move, in order, and none for a refused one', function (): void {
    $this->actingAs($this->admin);
    ($this->move)(160, 160)->assertOk();
    ($this->move)(260, 260)->assertOk();

    // Refused: off canvas. No third row.
    ($this->move)(99999, 260)->assertUnprocessable();

    $rows = ActivityLog::query()
        ->where('action', ActivityAction::AssetPositionChanged->value)
        ->where('subject_id', $this->pc->getKey())
        ->where('subject_type', $this->pc->getMorphClass())
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->properties['to'])->toEqual(['x' => 160, 'y' => 160])
        ->and($rows[1]->properties['from'])->toEqual(['x' => 160, 'y' => 160])
        ->and($rows[1]->properties['to'])->toEqual(['x' => 260, 'y' => 260]);
});

it('surfaces on the existing pc-unit audit timeline — no second history surface was built', function (): void {
    $this->actingAs($this->admin);
    ($this->move)(180, 180)->assertOk();

    $this->actingAs($this->admin)
        ->getJson("/api/admin/pc-units/{$this->pc->uuid}/audit")
        ->assertOk()
        ->assertJsonFragment(['action' => ActivityAction::AssetPositionChanged->value]);
});

it('a Technician cannot read the position-history entries via the pc-unit audit trail', function (): void {
    $this->actingAs($this->admin);
    ($this->move)(180, 180)->assertOk();

    $this->actingAs(userWithRole('technician'))
        ->getJson("/api/admin/pc-units/{$this->pc->uuid}/audit")
        ->assertForbidden();
});

it('the stale-write refusal exposes no register data beyond the standard narrow shape', function (): void {
    $this->actingAs($this->admin);
    $seenByClient = ($this->currentUpdatedAt)();
    ($this->move)(300, 300, $seenByClient)->assertOk();

    $response = ($this->move)(500, 500, $seenByClient)->assertStatus(409);

    expect(array_keys($response->json('current')))
        ->toBe(['id', 'name', 'unit_code', 'status', 'x', 'y', 'rotation', 'z_index', 'updated_at']);

    $encoded = (string) $response->getContent();
    foreach (['serial_number', 'ip_address', 'mac_address', 'room_id', 'pc_unit_id'] as $field) {
        expect($encoded)->not->toContain($field);
    }
});
