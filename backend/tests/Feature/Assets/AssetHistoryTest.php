<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\HardwareModel;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;
use App\Models\PcUnit;
use App\Models\Room;

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
});

it('merges every source into one chronological timeline', function () {
    $from = Room::factory()->create(['name' => 'Store Room']);
    $to = Room::factory()->create(['name' => 'Lab 3']);
    $asset = Asset::factory()->create(['current_room_id' => $from->id, 'status' => 'in_stock']);
    $pcUnit = PcUnit::factory()->create(['pc_name' => 'LAB1-PC-04']);

    // Creation → status change → transfer → QR → installation.
    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'deployed',
        'reason' => 'Installed.',
    ])->assertOk();

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/transfer", [
        'room' => $to->uuid,
    ])->assertOk();

    $this->actingAs($this->admin)->postJson("/api/admin/assets/{$asset->uuid}/qr")->assertCreated();

    $asset->installations()->create([
        'pc_unit_id' => $pcUnit->id,
        'installation_status' => 'installed',
        'installation_date' => now(),
    ]);

    $entries = $this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/history")
        ->assertOk()
        ->json('data');

    $types = collect($entries)->pluck('type')->unique();

    expect($types)->toContain('activity')   // creation, transfer, QR generation
        ->toContain('status')               // asset_status_history
        ->toContain('transfer')             // asset_transfers
        ->toContain('installation')         // pc_component_installations
        ->toContain('qr');                  // qr_codes

    // Newest first, and every entry is placed in time.
    $timestamps = collect($entries)->pluck('at')->filter()->values();
    expect($timestamps->toArray())->toBe($timestamps->sortDesc()->values()->toArray())
        ->and(collect($entries)->whereNull('at'))->toBeEmpty();
});

it('keeps the opening status entry so the story starts at creation', function () {
    $model = HardwareModel::factory()->create();

    $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'asset_tag' => 'HIST-1',
        'hardware_model' => $model->id,
        'status' => 'new',
    ])->assertCreated();

    $asset = Asset::query()->where('asset_tag', 'HIST-1')->firstOrFail();

    $entries = $this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/history")->assertOk()->json('data');

    $opening = collect($entries)->firstWhere('type', 'status');

    expect($opening['label'])->toBe('Opened as New')
        ->and($opening['properties']['from'])->toBeNull();
});

it('never loses history when the asset is archived', function () {
    $asset = Asset::factory()->create(['status' => 'in_stock']);

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}/status", [
        'status' => 'deployed',
    ])->assertOk();

    $before = count($this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/history")->json('data'));

    $this->actingAs($this->admin)->deleteJson("/api/admin/assets/{$asset->uuid}")->assertOk();

    // The archived record is still reachable (`withTrashed`), and its history is
    // intact — a soft delete is an UPDATE, so no foreign key was broken.
    $after = $this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/history")
        ->assertOk()
        ->json('data');

    expect(count($after))->toBeGreaterThanOrEqual($before);
});

it('paginates the timeline', function () {
    $asset = Asset::factory()->create(['status' => 'in_stock']);

    // Ten status changes, alternating so every transition is legal.
    foreach (range(1, 10) as $i) {
        $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}/status", [
            'status' => $i % 2 === 0 ? 'in_stock' : 'deployed',
        ])->assertOk();
    }

    $page = $this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/history?per_page=5&page=1")
        ->assertOk();

    expect($page->json('per_page'))->toBe(5)
        ->and($page->json('data'))->toHaveCount(5)
        ->and($page->json('total'))->toBeGreaterThan(5);
});

it('shows tickets and maintenance on a PC timeline', function () {
    $pcUnit = PcUnit::factory()->create();
    $technician = userWithRole('technician');
    $type = MaintenanceType::factory()->create();

    MaintenanceRecord::factory()->create([
        'pc_unit_id' => $pcUnit->id,
        'asset_id' => null,
        'technician_id' => $technician->id,
        'maintenance_type_id' => $type->id,
        'title' => 'Replaced the PSU',
        'maintenance_date' => now()->subDay(),
    ]);

    $entries = $this->actingAs($this->admin)
        ->getJson("/api/admin/pc-units/{$pcUnit->uuid}/history")
        ->assertOk()
        ->json('data');

    $maintenance = collect($entries)->firstWhere('type', 'maintenance');

    expect($maintenance)->not->toBeNull()
        ->and($maintenance['label'])->toBe('Replaced the PSU');
});

it('separates the audit trail from the timeline', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'name' => 'Renamed',
    ])->assertOk();

    // The audit endpoint answers "who did what, from where" — it carries the
    // actor and request metadata the timeline deliberately omits.
    $audit = $this->actingAs($this->admin)
        ->getJson("/api/admin/assets/{$asset->uuid}/audit")
        ->assertOk()
        ->json('data');

    expect($audit)->not->toBeEmpty()
        ->and(collect($audit)->pluck('action'))->toContain('asset_updated');
});
