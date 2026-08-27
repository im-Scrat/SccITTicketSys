<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\Building;
use App\Models\Floor;
use App\Models\HardwareModel;
use App\Models\Room;
use App\Models\Supplier;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
});

it('creates an asset and opens its status history at creation', function () {
    $model = HardwareModel::factory()->create();
    $room = Room::factory()->create();
    $supplier = Supplier::factory()->create();

    $response = $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'asset_tag' => 'lab-print-01',
        'name' => 'Lab 3 Front Printer',
        'hardware_model' => $model->id,
        'supplier' => $supplier->name,
        'room' => $room->uuid,
        'serial_number' => 'SN-CREATE-1',
        'status' => 'new',
        'condition' => 'working',
        'purchase_price' => 450.00,
        'purchase_date' => '2026-01-15',
        'warranty_expiration' => '2028-01-15',
        'notes' => 'Front of the room.',
    ])->assertCreated();

    // `asset_tag` is normalized to upper case before validation.
    $response->assertJsonPath('data.asset_tag', 'LAB-PRINT-01')
        ->assertJsonPath('data.name', 'Lab 3 Front Printer')
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.status_label', 'New')
        ->assertJsonPath('data.room.id', $room->uuid);

    $asset = Asset::query()->where('asset_tag', 'LAB-PRINT-01')->firstOrFail();

    // History starts at creation, not at the first edit (FR-AST-005).
    $opening = AssetStatusHistory::query()->where('asset_id', $asset->id)->firstOrFail();
    expect($opening->from_status)->toBeNull()
        ->and($opening->to_status->value)->toBe('new');

    expect(ActivityLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', ActivityAction::AssetCreated->value)
        ->where('module', 'assets')
        ->exists())->toBeTrue();
});

it('falls back to the catalog model name when no asset name is given', function () {
    $model = HardwareModel::factory()->create(['model_name' => 'OptiPlex 7090']);

    $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'asset_tag' => 'NONAME-1',
        'hardware_model' => $model->id,
    ])->assertCreated()
        ->assertJsonPath('data.name', null)
        ->assertJsonPath('data.display_name', 'OptiPlex 7090');
});

it('rejects a duplicate asset tag or serial number', function () {
    $existing = Asset::factory()->create(['asset_tag' => 'DUPE-1', 'serial_number' => 'SN-DUPE']);
    $model = HardwareModel::factory()->create();

    $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'asset_tag' => 'DUPE-1',
        'hardware_model' => $model->id,
    ])->assertStatus(422)->assertJsonValidationErrors('asset_tag');

    $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'asset_tag' => 'FRESH-1',
        'hardware_model' => $model->id,
        'serial_number' => 'SN-DUPE',
    ])->assertStatus(422)->assertJsonValidationErrors('serial_number');

    expect($existing->fresh())->not->toBeNull();
});

it('rejects a warranty that expires before the purchase date', function () {
    $model = HardwareModel::factory()->create();

    $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'asset_tag' => 'BADWARRANTY-1',
        'hardware_model' => $model->id,
        'purchase_date' => '2026-06-01',
        'warranty_expiration' => '2026-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors('warranty_expiration');
});

it('rejects a warranty before the stored purchase date on a partial update', function () {
    $asset = Asset::factory()->create([
        'purchase_date' => '2026-06-01',
        'warranty_expiration' => '2027-06-01',
    ]);

    // `purchase_date` is absent from the payload but set on the record — the
    // check must still fire (it cannot be a plain `after_or_equal` rule).
    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'warranty_expiration' => '2026-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors('warranty_expiration');
});

it('updates an asset and records a field-level diff', function () {
    $asset = Asset::factory()->create(['name' => 'Old name', 'serial_number' => 'SN-OLD']);

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'name' => 'New name',
        'serial_number' => 'SN-NEW',
    ])->assertOk()->assertJsonPath('data.name', 'New name');

    $log = ActivityLog::query()
        ->where('subject_id', $asset->id)
        ->where('action', ActivityAction::AssetUpdated->value)
        ->firstOrFail();

    // Key order is not part of the contract — jsonb does not preserve it.
    expect($log->properties['changes']['name'])->toEqualCanonicalizing(['from' => 'Old name', 'to' => 'New name'])
        ->and($log->properties['changes']['serial_number'])->toEqualCanonicalizing(['from' => 'SN-OLD', 'to' => 'SN-NEW']);
});

it('refuses to change status or room through a plain update', function () {
    $room = Room::factory()->create();
    $asset = Asset::factory()->create(['status' => 'in_stock']);

    // Neither field is accepted by the edit endpoint, so an ordinary update can
    // never bypass asset_status_history / asset_transfers (FR-AST-005/006).
    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'status' => 'deployed',
        'room' => $room->uuid,
    ])->assertOk();

    $asset->refresh();
    expect($asset->status->value)->toBe('in_stock')
        ->and($asset->current_room_id)->toBeNull();
});

it('archives and restores an asset without losing history', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'deployed',
    ])->assertOk();

    $historyBefore = AssetStatusHistory::query()->where('asset_id', $asset->id)->count();

    $this->actingAs($this->admin)->deleteJson("/api/admin/assets/{$asset->uuid}")->assertOk();
    expect(Asset::query()->find($asset->id))->toBeNull()
        ->and(Asset::withTrashed()->find($asset->id)->deleted_at)->not->toBeNull();

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/restore")->assertOk();

    $asset->refresh();
    expect($asset->deleted_at)->toBeNull()
        // The lifecycle status survives the round trip: archiving is orthogonal
        // to the lifecycle, not a point on it.
        ->and($asset->status->value)->toBe('deployed')
        ->and(AssetStatusHistory::query()->where('asset_id', $asset->id)->count())->toBe($historyBefore);
});

it('scopes the detail payload to full location context', function () {
    $building = Building::factory()->create(['name' => 'Science Hall']);
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Lab 3']);
    $asset = Asset::factory()->create(['current_room_id' => $room->id]);

    $this->actingAs($this->admin)->getJson("/api/admin/assets/{$asset->uuid}")
        ->assertOk()
        ->assertJsonPath('data.room.name', 'Lab 3')
        ->assertJsonPath('data.building.name', 'Science Hall')
        ->assertJsonPath('data.floor.id', $floor->uuid)
        // The client is told which transitions are legal rather than guessing.
        ->assertJsonStructure(['meta' => ['transitions', 'blockers', 'in_use']]);
});
