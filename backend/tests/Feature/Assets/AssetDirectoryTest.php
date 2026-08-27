<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use App\Models\Manufacturer;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Supplier;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
});

/** Build one fully-connected asset so the cross-table search has something to find. */
function connectedAsset(array $overrides = []): Asset
{
    $manufacturer = Manufacturer::factory()->create(['name' => 'Contoso']);
    $component = HardwareComponent::factory()->create([
        'manufacturer_id' => $manufacturer->id,
        'component_type' => 'printer',
        'name' => 'Office Laser Printer',
    ]);
    $model = HardwareModel::factory()->create([
        'hardware_component_id' => $component->id,
        'model_name' => 'LaserJet 4200',
        'model_number' => 'LJ-4200',
    ]);
    $supplier = Supplier::factory()->create(['name' => 'Northwind Supply']);

    $building = Building::factory()->create(['name' => 'Science Hall', 'code' => 'SCI']);
    $floor = Floor::factory()->create(['building_id' => $building->id, 'name' => 'Second Floor']);
    $room = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Lab 3', 'code' => 'SCI-L3']);

    return Asset::factory()->create([
        'asset_tag' => 'PRN-0001',
        'name' => 'Lab 3 Front Printer',
        'serial_number' => 'SN-PRINTER-9',
        'barcode' => '1234567890123',
        'hardware_model_id' => $model->id,
        'supplier_id' => $supplier->id,
        'current_room_id' => $room->id,
        ...$overrides,
    ]);
}

it('finds an asset by every field the enterprise search covers', function () {
    $asset = connectedAsset();
    $technician = userWithRole('technician', ['first_name' => 'Marisol', 'last_name' => 'Rivera']);
    $asset->update(['assigned_technician_id' => $technician->id]);
    $asset->qrCodes()->create(['code' => 'AS-FINDME01', 'status' => 'active', 'generated_at' => now()]);

    // Decoy that must never match any of the terms below.
    Asset::factory()->create(['asset_tag' => 'ZZZ-9999', 'name' => 'Unrelated', 'serial_number' => 'SN-ZZZ']);

    $terms = [
        'PRN-0001',            // asset tag
        'Front Printer',       // asset name
        'SN-PRINTER-9',        // serial number
        '1234567890123',       // barcode
        'LaserJet',            // catalog model name
        'LJ-4200',             // model number
        'Contoso',             // manufacturer
        'Office Laser',        // component name
        'Northwind',           // supplier
        'Science Hall',        // building
        'SCI',                 // building code
        'Second Floor',        // floor
        'Lab 3',               // room
        'SCI-L3',              // room code
        'Rivera',              // assigned technician
        'AS-FINDME01',         // QR code
    ];

    foreach ($terms as $term) {
        $rows = $this->actingAs($this->admin)
            ->getJson('/api/admin/assets?search='.urlencode($term))
            ->assertOk()
            ->json('data');

        expect(collect($rows)->pluck('asset_tag'))
            ->toContain('PRN-0001')
            ->and($rows)->toHaveCount(1);
    }
});

it('treats wildcard characters in a search term literally', function () {
    Asset::factory()->create(['asset_tag' => 'REAL-100', 'name' => 'Real asset']);
    Asset::factory()->create(['asset_tag' => 'PCT-100', 'name' => '100% cotton label']);

    // `%` must match the literal character, not act as a wildcard.
    $rows = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets?search='.urlencode('100%'))
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('asset_tag'))->toContain('PCT-100')
        ->and(collect($rows)->pluck('asset_tag'))->not->toContain('REAL-100');
});

it('filters by status, including a comma-separated list', function () {
    Asset::factory()->create(['asset_tag' => 'A-STOCK', 'status' => 'in_stock']);
    Asset::factory()->create(['asset_tag' => 'A-REPAIR', 'status' => 'in_repair']);
    Asset::factory()->create(['asset_tag' => 'A-OOS', 'status' => 'out_of_service']);

    $single = collect($this->actingAs($this->admin)
        ->getJson('/api/admin/assets?status=in_repair')->assertOk()->json('data'))->pluck('asset_tag');

    expect($single)->toContain('A-REPAIR')->and($single)->toHaveCount(1);

    // One dashboard tile can link to several states at once.
    $multi = collect($this->actingAs($this->admin)
        ->getJson('/api/admin/assets?status=in_repair,out_of_service')->assertOk()->json('data'))->pluck('asset_tag');

    expect($multi)->toContain('A-REPAIR')->toContain('A-OOS')->and($multi)->toHaveCount(2);
});

it('rejects an unknown filter value instead of silently ignoring it', function () {
    $this->actingAs($this->admin)->getJson('/api/admin/assets?status=not_a_status')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $this->actingAs($this->admin)->getJson('/api/admin/assets?category=not_a_category')
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');
});

it('filters by category, building and room', function () {
    $asset = connectedAsset();
    $room = $asset->currentRoom;
    $building = $room->floor->building;

    // The decoy's category is pinned: HardwareComponentFactory picks a random
    // ComponentType, which would otherwise land on `printer` now and then and
    // make this test flaky rather than wrong.
    $decoyComponent = HardwareComponent::factory()->create([
        'component_type' => 'cpu',
        'manufacturer_id' => Manufacturer::factory()->create(['name' => 'Fabrikam'])->id,
    ]);
    Asset::factory()->create([
        'asset_tag' => 'OTHER-1',
        'hardware_model_id' => HardwareModel::factory()->create([
            'hardware_component_id' => $decoyComponent->id,
        ])->id,
    ]);

    foreach ([
        'category=printer',
        'building='.$building->uuid,
        'room='.$room->uuid,
        'floor='.$room->floor->uuid,
        'supplier=Northwind Supply',
        'manufacturer=Contoso',
    ] as $filter) {
        $rows = $this->actingAs($this->admin)->getJson("/api/admin/assets?{$filter}")->assertOk()->json('data');

        expect(collect($rows)->pluck('asset_tag'))->toContain('PRN-0001')
            ->and($rows)->toHaveCount(1);
    }
});

it('filters unassigned custodianship with the explicit sentinel', function () {
    $technician = userWithRole('technician');
    Asset::factory()->create(['asset_tag' => 'HELD-1', 'assigned_technician_id' => $technician->id]);
    Asset::factory()->create(['asset_tag' => 'FREE-1', 'assigned_technician_id' => null]);

    $rows = collect($this->actingAs($this->admin)
        ->getJson('/api/admin/assets?technician=unassigned')->assertOk()->json('data'))->pluck('asset_tag');

    expect($rows)->toContain('FREE-1')->and($rows)->not->toContain('HELD-1');

    $held = collect($this->actingAs($this->admin)
        ->getJson('/api/admin/assets?technician='.$technician->uuid)->assertOk()->json('data'))->pluck('asset_tag');

    expect($held)->toContain('HELD-1')->and($held)->not->toContain('FREE-1');
});

it('filters warranties expiring inside a window and excludes lapsed ones', function () {
    Asset::factory()->create(['asset_tag' => 'SOON-1', 'warranty_expiration' => now()->addDays(10)]);
    Asset::factory()->create(['asset_tag' => 'LATER-1', 'warranty_expiration' => now()->addDays(400)]);
    Asset::factory()->create(['asset_tag' => 'LAPSED-1', 'warranty_expiration' => now()->subDays(30)]);

    $rows = collect($this->actingAs($this->admin)
        ->getJson('/api/admin/assets?warranty_expiring=90')->assertOk()->json('data'))->pluck('asset_tag');

    expect($rows)->toContain('SOON-1')
        ->and($rows)->not->toContain('LATER-1')
        ->and($rows)->not->toContain('LAPSED-1');
});

it('sorts on an allow-listed column and falls back safely for anything else', function () {
    Asset::factory()->create(['asset_tag' => 'AAA-1']);
    Asset::factory()->create(['asset_tag' => 'BBB-1']);
    Asset::factory()->create(['asset_tag' => 'CCC-1']);

    $asc = collect($this->actingAs($this->admin)
        ->getJson('/api/admin/assets?sort=asset_tag&direction=asc')->assertOk()->json('data'))->pluck('asset_tag');
    expect($asc->first())->toBe('AAA-1');

    $desc = collect($this->actingAs($this->admin)
        ->getJson('/api/admin/assets?sort=asset_tag&direction=desc')->assertOk()->json('data'))->pluck('asset_tag');
    expect($desc->first())->toBe('CCC-1');

    // A sort column outside the allow-list is rejected, so a crafted value can
    // never reach raw SQL.
    $this->actingAs($this->admin)
        ->getJson('/api/admin/assets?sort=(select+1)')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

it('sorts across joined relations without duplicating rows', function () {
    connectedAsset();
    Asset::factory()->create(['asset_tag' => 'PLAIN-1']);

    foreach (['model', 'category', 'room', 'building', 'supplier', 'technician'] as $sort) {
        $rows = $this->actingAs($this->admin)
            ->getJson("/api/admin/assets?sort={$sort}")->assertOk()->json('data');

        expect($rows)->toHaveCount(2);
    }
});

it('paginates server-side with a stable order', function () {
    foreach (range(1, 25) as $n) {
        Asset::factory()->create(['asset_tag' => sprintf('PAGE-%03d', $n)]);
    }

    $first = $this->actingAs($this->admin)->getJson('/api/admin/assets?per_page=10&page=1')->assertOk();
    $first->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonCount(10, 'data');

    $second = $this->actingAs($this->admin)->getJson('/api/admin/assets?per_page=10&page=2')->assertOk();

    // No row appears on two pages — the tiebreak keeps the order total.
    $overlap = collect($first->json('data'))->pluck('id')
        ->intersect(collect($second->json('data'))->pluck('id'));

    expect($overlap)->toBeEmpty();
});

it('caps per_page so a client cannot request the whole register', function () {
    $this->actingAs($this->admin)->getJson('/api/admin/assets?per_page=5000')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('shows archived assets only when asked', function () {
    $live = Asset::factory()->create(['asset_tag' => 'LIVE-1']);
    $archived = Asset::factory()->create(['asset_tag' => 'ARCH-1']);
    $archived->delete();

    $default = collect($this->actingAs($this->admin)->getJson('/api/admin/assets')->json('data'))->pluck('asset_tag');
    expect($default)->toContain('LIVE-1')->and($default)->not->toContain('ARCH-1');

    $only = collect($this->actingAs($this->admin)->getJson('/api/admin/assets?trashed=only')->json('data'))->pluck('asset_tag');
    expect($only)->toContain('ARCH-1')->and($only)->not->toContain('LIVE-1');

    $with = collect($this->actingAs($this->admin)->getJson('/api/admin/assets?trashed=with')->json('data'))->pluck('asset_tag');
    expect($with)->toContain('LIVE-1')->toContain('ARCH-1');

    expect($live->fresh())->not->toBeNull();
});

it('searches PC units across their own fields and their installed parts', function () {
    $room = Room::factory()->create(['name' => 'Lab 7']);
    $pcUnit = PcUnit::factory()->create([
        'unit_code' => 'PC-7001',
        'pc_name' => 'Lab 7 Station 1',
        'hostname' => 'lab7-ws1',
        'room_id' => $room->id,
    ]);

    $part = Asset::factory()->create(['asset_tag' => 'RAM-777']);
    $pcUnit->componentInstallations()->create([
        'asset_id' => $part->id,
        'installation_status' => 'installed',
        'installation_date' => now(),
    ]);

    PcUnit::factory()->create(['unit_code' => 'PC-9999', 'pc_name' => 'Elsewhere']);

    foreach (['PC-7001', 'Station 1', 'lab7-ws1', 'Lab 7', 'RAM-777'] as $term) {
        $rows = collect($this->actingAs($this->admin)
            ->getJson('/api/admin/pc-units?search='.urlencode($term))->assertOk()->json('data'))->pluck('unit_code');

        expect($rows)->toContain('PC-7001')
            ->and($rows)->not->toContain('PC-9999');
    }
});
