<?php

declare(strict_types=1);

use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\PcSpecification;
use App\Models\PcUnit;
use App\Models\Room;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
});

it('creates a PC unit with its specification snapshot', function () {
    $room = Room::factory()->create();

    $this->actingAs($this->admin)->postJson('/api/admin/pc-units', [
        'unit_code' => 'lab7-pc-01',
        'pc_name' => 'Lab 7 Station 1',
        'hostname' => 'lab7-ws1',
        'room' => $room->uuid,
        'ip_address' => '10.0.4.21',
        'mac_address' => '00:1B:44:11:3A:B7',
        'status' => 'available',
        'current_condition' => 'working',
        'purchase_date' => '2026-02-01',
        'warranty_expiration' => '2029-02-01',
        'specification' => [
            'cpu' => 'Intel Core i5-12400',
            'ram' => '16 GB DDR4-3200',
            'storage_primary' => '512 GB NVMe SSD',
            'operating_system' => 'Windows 11 Pro',
        ],
    ])->assertCreated()
        ->assertJsonPath('data.unit_code', 'LAB7-PC-01')
        ->assertJsonPath('data.specification.cpu', 'Intel Core i5-12400')
        ->assertJsonPath('data.network.ip_address', '10.0.4.21');

    $pcUnit = PcUnit::query()->where('unit_code', 'LAB7-PC-01')->firstOrFail();

    // The 1:1 row always exists, so the editor never has to handle "not yet".
    expect(PcSpecification::query()->where('pc_unit_id', $pcUnit->id)->count())->toBe(1);
});

it('always creates a specification row even when none is supplied', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/pc-units', [
        'unit_code' => 'BARE-1',
        'pc_name' => 'Bare PC',
    ])->assertCreated();

    $pcUnit = PcUnit::query()->where('unit_code', 'BARE-1')->firstOrFail();

    expect($pcUnit->specification)->not->toBeNull()
        ->and($pcUnit->specification->cpu)->toBeNull();
});

it('validates network fields against their database types', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/pc-units', [
        'unit_code' => 'BADNET-1',
        'pc_name' => 'Bad net',
        // `ip_address` maps to a Postgres `inet` column — an invalid value would
        // otherwise surface as a 500 from a failed cast.
        'ip_address' => 'not-an-ip',
        'mac_address' => 'ZZ:ZZ',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['ip_address', 'mac_address']);
});

it('edits every specification field and audits a field-level diff', function () {
    $pcUnit = PcUnit::factory()->create();
    PcSpecification::query()->create(['pc_unit_id' => $pcUnit->id, 'cpu' => 'Old CPU']);

    $payload = [];
    foreach (UpsertPcSpecification::FIELDS as $field) {
        $payload[$field] = 'Value for '.$field;
    }

    $response = $this->actingAs($this->admin)
        ->putJson("/api/admin/pc-units/{$pcUnit->uuid}/specification", $payload)
        ->assertOk();

    // Every column the editor claims to support really round-trips.
    foreach (UpsertPcSpecification::FIELDS as $field) {
        expect($response->json("data.{$field}"))->toBe('Value for '.$field);
    }

    $log = ActivityLog::query()
        ->where('subject_id', $pcUnit->id)
        ->where('action', ActivityAction::PcSpecificationUpdated->value)
        ->firstOrFail();

    expect($log->properties['changes']['cpu'])
        ->toEqualCanonicalizing(['from' => 'Old CPU', 'to' => 'Value for cpu']);
});

it('creates the specification row on first edit if it is missing', function () {
    $pcUnit = PcUnit::factory()->create();
    PcSpecification::query()->where('pc_unit_id', $pcUnit->id)->delete();

    $this->actingAs($this->admin)
        ->putJson("/api/admin/pc-units/{$pcUnit->uuid}/specification", ['cpu' => 'Ryzen 5 5600'])
        ->assertOk()
        ->assertJsonPath('data.cpu', 'Ryzen 5 5600');

    expect(PcSpecification::query()->where('pc_unit_id', $pcUnit->id)->count())->toBe(1);
});

it('treats a blank specification value as cleared, not as an empty string', function () {
    $pcUnit = PcUnit::factory()->create();
    PcSpecification::query()->create(['pc_unit_id' => $pcUnit->id, 'gpu' => 'RTX 3060']);

    $this->actingAs($this->admin)
        ->putJson("/api/admin/pc-units/{$pcUnit->uuid}/specification", ['gpu' => '   '])
        ->assertOk()
        ->assertJsonPath('data.gpu', null);
});

it('refuses to archive a PC that still holds installed components', function () {
    $pcUnit = PcUnit::factory()->create();
    $part = Asset::factory()->create();

    $pcUnit->componentInstallations()->create([
        'asset_id' => $part->id,
        'installation_status' => 'installed',
        'installation_date' => now(),
    ]);

    $this->actingAs($this->admin)->deleteJson("/api/admin/pc-units/{$pcUnit->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'asset_in_use')
        ->assertJsonPath('level', 'pc_unit')
        ->assertJsonPath('blockers.components', 1);

    expect($pcUnit->fresh()->deleted_at)->toBeNull();
});

it('archives and restores a PC once its components are removed', function () {
    $pcUnit = PcUnit::factory()->create();
    $part = Asset::factory()->create();

    $installation = $pcUnit->componentInstallations()->create([
        'asset_id' => $part->id,
        'installation_status' => 'installed',
        'installation_date' => now(),
    ]);

    // A removed component no longer blocks: history is not an obstruction.
    $installation->update(['installation_status' => 'removed', 'removal_date' => now()]);

    $this->actingAs($this->admin)->deleteJson("/api/admin/pc-units/{$pcUnit->uuid}")->assertOk();
    expect(PcUnit::query()->find($pcUnit->id))->toBeNull();

    $this->actingAs($this->admin)->postJson("/api/admin/pc-units/{$pcUnit->uuid}/restore")->assertOk();

    expect($pcUnit->fresh()->deleted_at)->toBeNull()
        // The installation history survived the round trip.
        ->and($pcUnit->componentInstallations()->count())->toBe(1);
});

it('rejects a duplicate unit code or hostname', function () {
    PcUnit::factory()->create(['unit_code' => 'DUP-1', 'hostname' => 'dup-host']);

    $this->actingAs($this->admin)->postJson('/api/admin/pc-units', [
        'unit_code' => 'DUP-1',
        'pc_name' => 'Clash',
    ])->assertStatus(422)->assertJsonValidationErrors('unit_code');

    $this->actingAs($this->admin)->postJson('/api/admin/pc-units', [
        'unit_code' => 'FRESH-1',
        'pc_name' => 'Clash',
        'hostname' => 'dup-host',
    ])->assertStatus(422)->assertJsonValidationErrors('hostname');
});

it('shows installed components on the PC detail page', function () {
    $pcUnit = PcUnit::factory()->create();
    $part = Asset::factory()->create(['asset_tag' => 'RAM-001', 'name' => 'Kingston 16GB']);

    $pcUnit->componentInstallations()->create([
        'asset_id' => $part->id,
        'installation_status' => 'installed',
        'installation_date' => now(),
    ]);

    $this->actingAs($this->admin)->getJson("/api/admin/pc-units/{$pcUnit->uuid}")
        ->assertOk()
        ->assertJsonPath('data.installations.0.asset.asset_tag', 'RAM-001')
        ->assertJsonPath('data.installations.0.current', true);
});
