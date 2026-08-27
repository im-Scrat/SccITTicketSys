<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\ActivityAction;
use App\Enums\AssetStatus;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\PcUnit;
use App\Models\Permission;
use App\Models\Room;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
});

it('extends the status domain additively without losing the original values', function () {
    // Every value legal before Phase 2.5 is still legal (SDD DD-32).
    foreach (['in_stock', 'deployed', 'in_repair', 'reserved', 'in_transit', 'retired', 'disposed'] as $legacy) {
        expect(AssetStatus::tryFrom($legacy))->not->toBeNull();
    }

    expect(AssetStatus::tryFrom('new'))->toBe(AssetStatus::New)
        ->and(AssetStatus::tryFrom('out_of_service'))->toBe(AssetStatus::OutOfService);
});

it('speaks the client vocabulary through status labels', function () {
    expect(AssetStatus::InStock->label())->toBe('Available')
        ->and(AssetStatus::Reserved->label())->toBe('Assigned')
        ->and(AssetStatus::Deployed->label())->toBe('In Service')
        ->and(AssetStatus::InRepair->label())->toBe('Maintenance')
        ->and(AssetStatus::OutOfService->label())->toBe('Out of Service');
});

it('records every transition in both history and audit, atomically', function () {
    $asset = Asset::factory()->create(['status' => 'in_stock']);

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'deployed',
        'reason' => 'Installed in Lab 3.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'deployed')
        ->assertJsonPath('data.status_label', 'In Service');

    $history = AssetStatusHistory::query()
        ->where('asset_id', $asset->id)
        ->where('to_status', 'deployed')
        ->firstOrFail();

    expect($history->from_status->value)->toBe('in_stock')
        ->and($history->reason)->toBe('Installed in Lab 3.')
        ->and($history->changed_by)->toBe($this->admin->id);

    expect(ActivityLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', ActivityAction::AssetStatusChanged->value)
        ->exists())->toBeTrue();
});

it('refuses an illegal transition and names the reachable states', function () {
    $asset = Asset::factory()->create(['status' => 'disposed']);

    // `disposed` is absorbing — the asset has left the organization.
    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'deployed',
    ])->assertStatus(422)->assertJsonValidationErrors('status');

    expect($asset->fresh()->status->value)->toBe('disposed');
});

it('treats re-submitting the current status as a no-op, not a history row', function () {
    $asset = Asset::factory()->create(['status' => 'in_stock']);
    $before = AssetStatusHistory::query()->where('asset_id', $asset->id)->count();

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'in_stock',
    ])->assertOk();

    expect(AssetStatusHistory::query()->where('asset_id', $asset->id)->count())->toBe($before);
});

it('requires assets.dispose for a terminal transition, not merely assets.update', function () {
    $asset = Asset::factory()->create(['status' => 'in_stock']);

    // An administrator whose dispose permission is individually denied keeps
    // ordinary status control but loses the write-off (SDD DD-33).
    $deputy = userWithRole('administrator');
    $dispose = Permission::query()->where('name', 'assets.dispose')->firstOrFail();
    $deputy->directPermissions()->attach($dispose->id, ['grant_type' => 'deny']);
    app(PermissionResolver::class)->forget($deputy);

    $this->actingAs($deputy)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'in_repair',
    ])->assertOk();

    $this->actingAs($deputy)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'retired',
    ])->assertForbidden();

    $this->actingAs($deputy)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'disposed',
    ])->assertForbidden();

    expect($asset->fresh()->status->value)->toBe('in_repair');
});

it('transfers an asset between rooms and writes the ledger', function () {
    $from = Room::factory()->create(['name' => 'Store Room']);
    $to = Room::factory()->create(['name' => 'Lab 3']);
    $asset = Asset::factory()->create(['current_room_id' => $from->id]);

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/transfer", [
        'room' => $to->uuid,
        'reason' => 'Deployed to the lab.',
    ])->assertOk()->assertJsonPath('data.room.id', $to->uuid);

    $asset->refresh();
    expect($asset->current_room_id)->toBe($to->id);

    // The register's current answer and its history agree, because both were
    // written in one transaction (FR-AST-006).
    $transfer = $asset->transfers()->firstOrFail();
    expect($transfer->from_room_id)->toBe($from->id)
        ->and($transfer->to_room_id)->toBe($to->id)
        ->and($transfer->transferred_by)->toBe($this->admin->id);

    expect(ActivityLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', ActivityAction::AssetTransferred->value)
        ->exists())->toBeTrue();
});

it('allows a transfer out of a room with no destination', function () {
    $from = Room::factory()->create();
    $asset = Asset::factory()->create(['current_room_id' => $from->id]);

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/transfer", [
        'room' => null,
    ])->assertOk();

    expect($asset->fresh()->current_room_id)->toBeNull()
        ->and($asset->transfers()->first()->to_room_id)->toBeNull();
});

it('refuses to transfer a disposed asset', function () {
    $room = Room::factory()->create();
    $asset = Asset::factory()->create(['status' => 'disposed', 'current_room_id' => null]);

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/transfer", [
        'room' => $room->uuid,
    ])->assertStatus(422)->assertJsonValidationErrors('room');
});

it('refuses to transfer an asset to the room it already occupies', function () {
    $room = Room::factory()->create();
    $asset = Asset::factory()->create(['current_room_id' => $room->id]);

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/transfer", [
        'room' => $room->uuid,
    ])->assertStatus(422)->assertJsonValidationErrors('room');
});

it('assigns and unassigns a custodian as distinct audited events', function () {
    $asset = Asset::factory()->create();
    $technician = userWithRole('technician');

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/assign", [
        'technician' => $technician->uuid,
    ])->assertOk()->assertJsonPath('data.technician.id', $technician->uuid);

    expect($asset->fresh()->assigned_technician_id)->toBe($technician->id);

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/assign", [
        'technician' => null,
    ])->assertOk()->assertJsonPath('data.technician', null);

    expect($asset->fresh()->assigned_technician_id)->toBeNull();

    foreach ([ActivityAction::AssetTechnicianAssigned, ActivityAction::AssetTechnicianUnassigned] as $action) {
        expect(ActivityLog::query()
            ->where('subject_id', $asset->id)
            ->where('action', $action->value)
            ->exists())->toBeTrue();
    }
});

it('refuses to assign an asset to a teacher', function () {
    $asset = Asset::factory()->create();
    $teacher = userWithRole('teacher');

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/assign", [
        'technician' => $teacher->uuid,
    ])->assertStatus(422)->assertJsonValidationErrors('technician');
});

it('refuses to archive an asset installed inside a PC', function () {
    $pcUnit = PcUnit::factory()->create(['pc_name' => 'LAB1-PC-04']);
    $asset = Asset::factory()->create();

    $asset->installations()->create([
        'pc_unit_id' => $pcUnit->id,
        'installation_status' => 'installed',
        'installation_date' => now(),
    ]);

    $this->actingAs($this->admin)->deleteJson("/api/admin/assets/{$asset->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'asset_in_use')
        ->assertJsonPath('level', 'asset')
        ->assertJsonPath('blockers.installed', 1)
        // The refusal names the machine holding it, so the operator knows where
        // to go next rather than just being told "no".
        ->assertJsonPath('installed_in.name', 'LAB1-PC-04');

    expect($asset->fresh()->deleted_at)->toBeNull();
});
