<?php

declare(strict_types=1);

use App\Domains\FloorPlan\Exceptions\FloorPlanRuleViolation;
use App\Domains\FloorPlan\Services\RoomLayoutService;
use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\PermissionGrantType;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WP-B — RoomLayoutService, and the direct-identifier (IDOR) behaviour of the
 * floor plan.
 *
 * The rule under test: **a valid identifier is not a credential.** Every case
 * that succeeds for an Administrator is repeated with the *same real uuid* for a
 * Technician and a Teacher and must be refused — and refused before the lookup,
 * so the answer is the same whether or not the uuid exists.
 *
 * There is no HTTP surface yet (the read API is a later package), so these
 * exercise the service boundary directly: the layer a future controller must go
 * through, and the one that has to hold when a controller forgets `authorize()`.
 */
beforeEach(function (): void {
    seedRbac();

    $this->service = app(RoomLayoutService::class);
    $this->admin = userWithRole('administrator');

    $this->room = Room::factory()->create();
    $this->layout = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 1, 'is_active' => true]);
    $this->pc = PcUnit::factory()->create(['room_id' => $this->room->id]);

    // A second room, with its own layout and its own PC — the "other" side of
    // every mismatch case.
    $this->otherRoom = Room::factory()->create();
    $this->otherLayout = RoomLayout::factory()->create(['room_id' => $this->otherRoom->id, 'version' => 1, 'is_active' => true]);
    $this->otherPc = PcUnit::factory()->create(['room_id' => $this->otherRoom->id]);
});

// ── Administrator: valid identifiers succeed ────────────────────────────────

it('resolves a room by uuid for an Administrator', function (): void {
    expect($this->service->room($this->admin, $this->room->uuid)->is($this->room))->toBeTrue();
});

it('resolves a layout as (room, version) for an Administrator', function (): void {
    expect($this->service->layout($this->admin, $this->room, 1)->is($this->layout))->toBeTrue();
});

it('resolves a PC unit of the layout\'s room by uuid for an Administrator', function (): void {
    expect($this->service->pcUnit($this->admin, $this->layout, $this->pc->uuid)->is($this->pc))->toBeTrue();
});

it('returns the active layout for an Administrator', function (): void {
    expect($this->service->activeLayout($this->admin, $this->room)?->is($this->layout))->toBeTrue();
});

// ── Teacher and Technician: the same valid identifiers are refused ─────────

it('refuses every lookup to a non-administrator, even with real identifiers', function (string $role): void {
    $actor = userWithRole($role);

    $attempts = [
        'room by uuid' => fn () => $this->service->room($actor, $this->room->uuid),
        'active layout' => fn () => $this->service->activeLayout($actor, $this->room),
        'layout by version' => fn () => $this->service->layout($actor, $this->room, 1),
        'pc unit by uuid' => fn () => $this->service->pcUnit($actor, $this->layout, $this->pc->uuid),
    ];

    foreach ($attempts as $name => $attempt) {
        try {
            $attempt();
            $this->fail("{$role} was not refused: {$name}");
        } catch (AuthorizationException) {
            expect(true)->toBeTrue();
        }
    }
})->with(['technician', 'teacher']);

it('refuses a non-administrator with the same 403 whether or not the uuid exists', function (string $role): void {
    $actor = userWithRole($role);
    $real = fn () => $this->service->room($actor, $this->room->uuid);
    $absent = fn () => $this->service->room($actor, (string) Str::uuid());
    $malformed = fn () => $this->service->room($actor, 'not-a-uuid');

    // Authorization precedes the lookup, so there is no 404 to tell a real room
    // from an invented one.
    expect($real)->toThrow(AuthorizationException::class)
        ->and($absent)->toThrow(AuthorizationException::class)
        ->and($malformed)->toThrow(AuthorizationException::class);
})->with(['technician', 'teacher']);

it('does not let a per-user floorplan grant open the service to a Technician', function (): void {
    $technician = userWithRole('technician');
    $ids = Permission::query()->whereIn('name', ['floorplan.view', 'floorplan.manage'])->pluck('id');
    foreach ($ids as $id) {
        DB::table('user_permissions')->insert([
            'user_id' => $technician->id, 'permission_id' => $id, 'grant_type' => PermissionGrantType::Grant->value,
        ]);
    }
    app(PermissionResolver::class)->forget($technician);

    expect($technician->hasPermissionTo('floorplan.view'))->toBeTrue()
        ->and(fn () => $this->service->room($technician, $this->room->uuid))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->layout($technician, $this->room, 1))->toThrow(AuthorizationException::class);
});

// ── Room / layout / PC relationships ────────────────────────────────────────

it('will not resolve a layout through a room it does not belong to', function (): void {
    // The other room has a version 1 as well; the pair (this room, version 1)
    // must give *this* room's layout, never the other's — and a version this
    // room does not have is simply not found.
    expect($this->service->layout($this->admin, $this->room, 1)->id)->toBe($this->layout->id)
        ->and($this->service->layout($this->admin, $this->otherRoom, 1)->id)->toBe($this->otherLayout->id)
        ->and(fn () => $this->service->layout($this->admin, $this->room, 2))->toThrow(ModelNotFoundException::class);
});

it('finds a historical version by room and version, and only in its own room', function (): void {
    $this->layout->update(['is_active' => false]);
    $v2 = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'is_active' => true]);

    expect($this->service->layout($this->admin, $this->room, 1)->is($this->layout))->toBeTrue()
        ->and($this->service->layout($this->admin, $this->room, 2)->is($v2))->toBeTrue()
        ->and(fn () => $this->service->layout($this->admin, $this->otherRoom, 2))->toThrow(ModelNotFoundException::class);
});

it('does not find a PC unit that belongs to another room', function (): void {
    // Valid uuid, real PC, wrong room: not found, not "found but refused" — the
    // response must not confirm the uuid exists elsewhere.
    expect(fn () => $this->service->pcUnit($this->admin, $this->layout, $this->otherPc->uuid))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects a pre-resolved PC unit from another room with a 422 rule violation', function (): void {
    try {
        $this->service->assertPcUnitInRoom($this->layout, $this->otherPc);
        $this->fail('Expected the cross-room PC unit to be refused.');
    } catch (FloorPlanRuleViolation $e) {
        expect($e->errorCode())->toBe('pc_unit_not_in_room')
            ->and($e->render()->getStatusCode())->toBe(422);
    }
});

it('rejects a PC unit that is not homed in any room', function (): void {
    $homeless = PcUnit::factory()->create(['room_id' => null]);

    expect(fn () => $this->service->assertPcUnitInRoom($this->layout, $homeless))
        ->toThrow(FloorPlanRuleViolation::class);
});

it('accepts a PC unit that belongs to the layout\'s room', function (): void {
    $this->service->assertPcUnitInRoom($this->layout, $this->pc);

    expect(true)->toBeTrue();
});

it('will not pair a layout with the other room\'s PC unit in either direction', function (): void {
    expect(fn () => $this->service->assertPcUnitInRoom($this->layout, $this->otherPc))
        ->toThrow(FloorPlanRuleViolation::class)
        ->and(fn () => $this->service->assertPcUnitInRoom($this->otherLayout, $this->pc))
        ->toThrow(FloorPlanRuleViolation::class);
});

// ── Active layout ───────────────────────────────────────────────────────────

it('treats only the active layout as editable, with a 409', function (): void {
    $this->service->assertEditable($this->layout);

    $this->layout->update(['is_active' => false]);

    try {
        $this->service->assertEditable($this->layout->refresh());
        $this->fail('Expected the inactive layout to be refused.');
    } catch (FloorPlanRuleViolation $e) {
        expect($e->errorCode())->toBe('layout_not_active')
            ->and($e->render()->getStatusCode())->toBe(409);
    }
});

it('reports no active layout when a room has none', function (): void {
    $this->layout->update(['is_active' => false]);

    expect($this->service->activeLayout($this->admin, $this->room))->toBeNull();
});

it('returns the single active layout when older versions exist', function (): void {
    $this->layout->update(['is_active' => false]);
    $v2 = RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'is_active' => true]);

    expect($this->service->activeLayout($this->admin, $this->room)?->is($v2))->toBeTrue();
});

it('has the database itself refuse a second active layout for a room', function (): void {
    // The application-side reading of the rule (assertEditable) rests on this
    // partial unique index; assert the constraint by name, not a generic error.
    try {
        RoomLayout::factory()->create(['room_id' => $this->room->id, 'version' => 2, 'is_active' => true]);
        $this->fail('Expected the one-active-layout index to refuse the insert.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('room_layouts_one_active_per_room');
    }
});

// ── Non-existent and malformed identifiers (as an Administrator) ────────────

it('answers a nonexistent uuid with not-found', function (): void {
    expect(fn () => $this->service->room($this->admin, (string) Str::uuid()))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->service->pcUnit($this->admin, $this->layout, (string) Str::uuid()))
        ->toThrow(ModelNotFoundException::class);
});

it('answers a malformed uuid with not-found rather than a database error', function (): void {
    expect(fn () => $this->service->room($this->admin, 'not-a-uuid'))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->service->pcUnit($this->admin, $this->layout, "1' OR '1'='1"))
        ->toThrow(ModelNotFoundException::class);
});

it('never resolves a room by its numeric id', function (): void {
    // NFR-SEC-001: internal ids are not addressable.
    expect(fn () => $this->service->room($this->admin, (string) $this->room->getKey()))
        ->toThrow(ModelNotFoundException::class);
});

// ── Archived records ────────────────────────────────────────────────────────

it('treats an archived room as gone', function (): void {
    $this->room->delete();

    expect(fn () => $this->service->room($this->admin, $this->room->uuid))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->service->activeLayout($this->admin, $this->room))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->service->layout($this->admin, $this->room, 1))->toThrow(ModelNotFoundException::class);
});

it('does not find an archived PC unit', function (): void {
    $this->pc->delete();

    expect(fn () => $this->service->pcUnit($this->admin, $this->layout, $this->pc->uuid))
        ->toThrow(ModelNotFoundException::class);
});

// ── No identifier bypass through the type system ────────────────────────────

it('cannot be called without an authenticated actor', function (): void {
    // The actor parameter is a non-nullable User: an unauthenticated caller
    // has no way to invoke the service at all.
    $method = new ReflectionMethod(RoomLayoutService::class, 'room');
    $type = $method->getParameters()[0]->getType();

    expect($type?->getName())->toBe(User::class)
        ->and($type?->allowsNull())->toBeFalse();
});
