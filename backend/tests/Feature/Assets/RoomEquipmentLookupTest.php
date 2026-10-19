<?php

declare(strict_types=1);

use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\InstallationStatus;
use App\Enums\PcStatus;
use App\Models\PcComponentInstallation;
use App\Models\PcUnit;
use App\Models\Room;
use Database\Seeders\TicketLookupSeeder;

/*
 * What the report form's "which item has the problem?" list offers for one room
 * (SDD DD-78; `ReportableEquipment`): the room's PCs that are not retired, and
 * its in-service equipment that is not fitted inside a PC and is of a type a
 * teacher can point at. The same definition decides what `POST /api/tickets`
 * accepts — that half is TicketEquipmentLinkTest.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);

    $this->teacher = userWithRole('teacher');
    $this->room = Room::factory()->create();
});

/**
 * The identifiers each list returns for one room, in the order returned.
 *
 * @param  array<string, mixed>  $query
 * @return array{pc_units: list<string>, assets: list<string>}
 */
function equipmentIn(mixed $test, Room $room, array $query = []): array
{
    $data = $test->actingAs($test->teacher)
        ->getJson('/api/lookups/equipment?'.http_build_query(['room' => $room->uuid, ...$query]))
        ->assertOk()
        ->json('data');

    return [
        'pc_units' => array_column($data['pc_units'], 'identifier'),
        'assets' => array_column($data['assets'], 'identifier'),
    ];
}

it('lists only the chosen room’s equipment', function () {
    $elsewhere = Room::factory()->create();

    PcUnit::factory()->create(['room_id' => $this->room->id, 'unit_code' => 'HERE-PC']);
    PcUnit::factory()->create(['room_id' => $elsewhere->id, 'unit_code' => 'THERE-PC']);
    assetOfType(ComponentType::Printer, ['current_room_id' => $this->room->id, 'asset_tag' => 'HERE-PRN', 'status' => AssetStatus::Deployed->value]);
    assetOfType(ComponentType::Printer, ['current_room_id' => $elsewhere->id, 'asset_tag' => 'THERE-PRN', 'status' => AssetStatus::Deployed->value]);

    expect(equipmentIn($this, $this->room))->toBe(['pc_units' => ['HERE-PC'], 'assets' => ['HERE-PRN']]);
});

it('leaves out archived PCs, but keeps every repair status — a broken PC is exactly what gets reported', function () {
    // Phase 2.11 (DD-100): "retired" became archiving, so the soft-delete
    // scope is the whole rule.
    PcUnit::factory()->create(['room_id' => $this->room->id, 'unit_code' => 'PC-LIVE', 'status' => PcStatus::Available->value]);
    PcUnit::factory()->create(['room_id' => $this->room->id, 'unit_code' => 'PC-REPAIR', 'status' => PcStatus::PendingRepair->value]);
    PcUnit::factory()->create(['room_id' => $this->room->id, 'unit_code' => 'PC-BROKEN', 'status' => PcStatus::NeedsFix->value]);
    PcUnit::factory()->create(['room_id' => $this->room->id, 'unit_code' => 'PC-ARCHIVED'])->delete();

    expect(equipmentIn($this, $this->room)['pc_units'])->toEqualCanonicalizing(['PC-LIVE', 'PC-REPAIR', 'PC-BROKEN']);
});

it('lists in-service equipment only — never spare stock, retired, disposed or archived', function () {
    $offered = [AssetStatus::Deployed, AssetStatus::InRepair, AssetStatus::OutOfService];
    $hidden = [AssetStatus::New, AssetStatus::InStock, AssetStatus::Reserved, AssetStatus::InTransit, AssetStatus::Retired, AssetStatus::Disposed];

    foreach ([...$offered, ...$hidden] as $status) {
        assetOfType(ComponentType::Projector, [
            'current_room_id' => $this->room->id,
            'asset_tag' => 'PRJ-'.strtoupper($status->value),
            'status' => $status->value,
        ]);
    }
    assetOfType(ComponentType::Projector, [
        'current_room_id' => $this->room->id,
        'asset_tag' => 'PRJ-ARCHIVED',
        'status' => AssetStatus::Deployed->value,
    ])->delete();

    expect(equipmentIn($this, $this->room)['assets'])
        ->toEqualCanonicalizing(array_map(fn (AssetStatus $s): string => 'PRJ-'.strtoupper($s->value), $offered));
});

it('leaves out a part fitted inside a PC, but offers one that has been removed', function () {
    $fitted = assetOfType(ComponentType::Monitor, ['current_room_id' => $this->room->id, 'asset_tag' => 'MON-FITTED', 'status' => AssetStatus::Deployed->value]);
    $removed = assetOfType(ComponentType::Monitor, ['current_room_id' => $this->room->id, 'asset_tag' => 'MON-REMOVED', 'status' => AssetStatus::Deployed->value]);
    $host = PcUnit::factory()->create(['room_id' => $this->room->id]);

    PcComponentInstallation::factory()->create(['pc_unit_id' => $host->id, 'asset_id' => $fitted->id]);
    PcComponentInstallation::factory()->create([
        'pc_unit_id' => $host->id,
        'asset_id' => $removed->id,
        'installation_status' => InstallationStatus::Removed->value,
        'removal_date' => now(),
    ]);

    expect(equipmentIn($this, $this->room)['assets'])->toBe(['MON-REMOVED']);
});

it('offers whole equipment, attached devices and legacy “other”, never internal parts', function () {
    foreach ([ComponentType::Printer, ComponentType::Monitor, ComponentType::Other, ComponentType::Ram, ComponentType::Cpu] as $type) {
        assetOfType($type, [
            'current_room_id' => $this->room->id,
            'asset_tag' => 'T-'.strtoupper($type->value),
            'status' => AssetStatus::Deployed->value,
        ]);
    }

    expect(equipmentIn($this, $this->room)['assets'])->toEqualCanonicalizing(['T-PRINTER', 'T-MONITOR', 'T-OTHER']);
});

it('still offers equipment whose catalog model has been archived, by its model name', function () {
    $printer = assetOfType(ComponentType::Printer, [
        'current_room_id' => $this->room->id,
        'asset_tag' => 'PRN-OLDMODEL',
        'name' => null,
        'status' => AssetStatus::Deployed->value,
    ]);
    $model = $printer->hardwareModel;
    $model->delete();

    $row = $this->actingAs($this->teacher)
        ->getJson('/api/lookups/equipment?room='.$this->room->uuid)
        ->assertOk()
        ->json('data.assets.0');

    expect($row['identifier'])->toBe('PRN-OLDMODEL')
        ->and($row['label'])->toBe($model->model_name.' (PRN-OLDMODEL)');
});

it('caps each list at the requested limit', function () {
    foreach (range(1, 3) as $i) {
        PcUnit::factory()->create(['room_id' => $this->room->id]);
        assetOfType(ComponentType::Scanner, ['current_room_id' => $this->room->id, 'status' => AssetStatus::Deployed->value]);
    }

    $lists = equipmentIn($this, $this->room, ['limit' => 2]);

    expect($lists['pc_units'])->toHaveCount(2)
        ->and($lists['assets'])->toHaveCount(2);
});

it('orders each list the way people read labels — PC 2 before PC 10', function () {
    PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC 10', 'unit_code' => 'A-10']);
    PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC 2', 'unit_code' => 'B-02']);
    PcUnit::factory()->create(['room_id' => $this->room->id, 'pc_name' => 'PC 1', 'unit_code' => 'C-01']);

    $labels = array_column(
        $this->actingAs($this->teacher)->getJson('/api/lookups/equipment?room='.$this->room->uuid)->json('data.pc_units'),
        'label',
    );

    expect($labels)->toBe(['PC 1 (C-01)', 'PC 2 (B-02)', 'PC 10 (A-10)']);
});

it('takes no search parameter, so it cannot be probed on fields it withholds', function () {
    PcUnit::factory()->create(['room_id' => $this->room->id, 'unit_code' => 'PC-ONE', 'serial_number' => 'SN-PROBE-ME']);
    assetOfType(ComponentType::Printer, [
        'current_room_id' => $this->room->id,
        'asset_tag' => 'PRN-ONE',
        'serial_number' => 'SN-PROBE-ME-TOO',
        'status' => AssetStatus::Deployed->value,
    ]);

    // A "search" for a serial fragment, and one for nonsense, return the same
    // full lists: the answer says nothing about what the serial contains.
    expect(equipmentIn($this, $this->room, ['search' => 'PROBE']))
        ->toBe(equipmentIn($this, $this->room, ['search' => 'zzz-no-match']))
        ->toBe(['pc_units' => ['PC-ONE'], 'assets' => ['PRN-ONE']]);
});
