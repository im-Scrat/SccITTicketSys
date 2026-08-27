<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use App\Models\Room;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
    Cache::flush();
});

it('summarizes the register in the client vocabulary', function () {
    Asset::factory()->count(2)->create(['status' => 'in_stock']);
    Asset::factory()->create(['status' => 'deployed']);
    Asset::factory()->create(['status' => 'in_repair']);
    Asset::factory()->create(['status' => 'out_of_service']);
    Asset::factory()->create(['status' => 'new']);
    Asset::factory()->create(['status' => 'reserved']);

    $archived = Asset::factory()->create(['status' => 'in_stock']);
    $archived->delete();

    $summary = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets/dashboard')
        ->assertOk()
        ->json('data.summary');

    expect($summary['total'])->toBe(7)          // archived rows are excluded
        ->and($summary['available'])->toBe(2)   // in_stock
        ->and($summary['in_service'])->toBe(1)  // deployed
        ->and($summary['maintenance'])->toBe(1) // in_repair
        ->and($summary['out_of_service'])->toBe(1)
        ->and($summary['new'])->toBe(1)
        ->and($summary['assigned'])->toBe(1)    // reserved
        ->and($summary['archived'])->toBe(1);
});

it('returns every card the module dashboard renders', function () {
    Asset::factory()->count(3)->create();

    $data = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets/dashboard')
        ->assertOk()
        ->json('data');

    expect(array_keys($data))->toContain(
        'summary',
        'by_status',
        'by_building',
        'by_room',
        'by_category',
        'warranty_expiring',
        'recently_added',
        'recently_updated',
        'pc_units',
        'filters',
        'generated_at',
    );
});

it('makes every distribution row a navigable filter target', function () {
    $building = Building::factory()->create(['name' => 'Science Hall']);
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Lab 3']);
    Asset::factory()->count(2)->create(['current_room_id' => $room->id]);

    $data = $this->actingAs($this->admin)->getJson('/api/admin/assets/dashboard')->assertOk()->json('data');

    $buildingRow = collect($data['by_building'])->firstWhere('label', 'Science Hall');
    $roomRow = collect($data['by_room'])->first();

    // A count you cannot act on is decoration — each row carries the uuid the
    // directory filter needs.
    expect($buildingRow['key'])->toBe($building->uuid)
        ->and($buildingRow['count'])->toBe(2)
        ->and($roomRow['key'])->toBe($room->uuid)
        ->and($roomRow['label'])->toBe('Science Hall · Lab 3');

    // And the link actually works.
    $filtered = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets?building='.$buildingRow['key'])
        ->assertOk()
        ->json('data');

    expect($filtered)->toHaveCount(2);
});

it('reports unplaced assets so they can be found', function () {
    Asset::factory()->create(['current_room_id' => null]);
    $room = Room::factory()->create();
    Asset::factory()->create(['current_room_id' => $room->id]);

    $summary = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets/dashboard')->assertOk()->json('data.summary');

    expect($summary['unassigned_location'])->toBe(1);
});

it('lists warranties expiring inside the window, soonest first', function () {
    Asset::factory()->create(['asset_tag' => 'W-LATE', 'warranty_expiration' => now()->addDays(60)]);
    Asset::factory()->create(['asset_tag' => 'W-SOON', 'warranty_expiration' => now()->addDays(5)]);
    Asset::factory()->create(['asset_tag' => 'W-OUT', 'warranty_expiration' => now()->addDays(500)]);
    Asset::factory()->create(['asset_tag' => 'W-GONE', 'warranty_expiration' => now()->subDays(5)]);

    $expiring = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets/dashboard')->assertOk()->json('data.warranty_expiring');

    $tags = collect($expiring['items'])->pluck('asset_tag');

    expect($expiring['days'])->toBe(90)
        ->and($expiring['count'])->toBe(2)
        ->and($tags->first())->toBe('W-SOON')
        ->and($tags)->toContain('W-LATE')
        ->and($tags)->not->toContain('W-OUT')
        ->and($tags)->not->toContain('W-GONE')
        ->and(collect($expiring['items'])->firstWhere('asset_tag', 'W-SOON')['days_remaining'])
        ->toBeGreaterThan(0);
});

it('groups the category distribution by catalog type', function () {
    $component = HardwareComponent::factory()->create(['component_type' => 'printer']);
    $model = HardwareModel::factory()->create(['hardware_component_id' => $component->id]);
    Asset::factory()->count(3)->create(['hardware_model_id' => $model->id]);

    $categories = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets/dashboard')->assertOk()->json('data.by_category');

    $printers = collect($categories)->firstWhere('key', 'printer');

    expect($printers['count'])->toBe(3)
        ->and($printers['label'])->toBe('Printer');
});

it('serves the catalog the create form needs in one request', function () {
    $data = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets/catalog')->assertOk()->json('data');

    expect(array_keys($data))->toContain(
        'statuses', 'conditions', 'categories', 'manufacturers',
        'suppliers', 'models', 'technicians', 'pc_statuses',
    );

    // Statuses carry the tone the client renders, so colour is decided once.
    $available = collect($data['statuses'])->firstWhere('value', 'in_stock');
    expect($available['label'])->toBe('Available')->and($available['tone'])->toBe('neutral');

    // Categories are grouped so equipment reads apart from internal parts.
    expect(collect($data['categories'])->firstWhere('value', 'printer')['group'])->toBe('equipment')
        ->and(collect($data['categories'])->firstWhere('value', 'cpu')['group'])->toBe('component');
});

it('offers only technicians and administrators as custodians', function () {
    $technician = userWithRole('technician');
    $teacher = userWithRole('teacher');

    $technicians = $this->actingAs($this->admin)
        ->getJson('/api/admin/assets/catalog')->assertOk()->json('data.technicians');

    $ids = collect($technicians)->pluck('value');

    expect($ids)->toContain($technician->uuid)
        ->toContain($this->admin->uuid)
        ->and($ids)->not->toContain($teacher->uuid);
});

/*
 * Role-dashboard integration (FR-DSH-003/004): one DashboardService, widgets
 * injected by permission. These pin that the Phase 2.5 additions respect it.
 */

it('shows the administrator the asset estate on the role dashboard', function () {
    Asset::factory()->count(4)->create(['status' => 'deployed']);

    $widgets = $this->actingAs($this->admin)
        ->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    $keys = collect($widgets)->pluck('key');

    expect($keys)->toContain('assets')
        ->toContain('assets-by-status')
        ->toContain('assets-by-building')
        ->toContain('warranty-expiring');
});

it('shows a technician only their own charges, never the estate', function () {
    $technician = userWithRole('technician');

    Asset::factory()->create(['assigned_technician_id' => $technician->id, 'status' => 'in_repair']);
    Asset::factory()->count(5)->create(['status' => 'deployed']); // someone else's

    $widgets = $this->actingAs($technician)
        ->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    $keys = collect($widgets)->pluck('key');

    // Their own work, yes; the cross-estate figures, no.
    expect($keys)->toContain('my-assets')
        ->and($keys)->not->toContain('assets-by-building')
        ->and($keys)->not->toContain('assets-by-room');

    $myAssets = collect($widgets)->firstWhere('key', 'my-assets');
    $assigned = collect($myAssets['items'])->firstWhere('key', 'assigned');

    expect($assigned['value'])->toBe(1);
});

it('never puts asset figures on a teacher dashboard', function () {
    Asset::factory()->count(3)->create();
    $teacher = userWithRole('teacher');

    $payload = $this->actingAs($teacher)->getJson('/api/dashboard/widgets')->assertOk()->json('data');

    $keys = collect($payload['widgets'])->pluck('key');

    foreach (['assets', 'my-assets', 'assets-by-status', 'assets-by-building', 'assets-by-room', 'warranty-expiring'] as $assetWidget) {
        expect($keys)->not->toContain($assetWidget);
    }

    // The payload cannot leak what it never assembles.
    expect(json_encode($payload))->not->toContain('asset');
});
