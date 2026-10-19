<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;
use Database\Seeders\ChecklistTemplateSeeder;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\SystemSettingSeeder;

/*
 * The maintenance reading surfaces (SRS FR-MNT-003/007/011).
 *
 * Three properties this file pins:
 *
 *   1. Every filter narrows to exactly what it claims, and an unknown value is
 *      a 422 rather than a silently unfiltered list under a filter chip.
 *   2. The three technician lists divide the work correctly — open, finished,
 *      and the dated horizon — and none of them leaks another technician's row.
 *   3. `sort` outside the allow-list is refused, so no crafted value reaches an
 *      order clause.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->seed(ChecklistTemplateSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
});

/* ------------------------------------------------------------- the three lists */

it('splits a technician work into the open queue and the finished history', function () {
    $open = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    $held = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::OnHold->value]);
    $done = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Completed->value]);
    $void = maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Cancelled->value]);

    $queue = collect($this->actingAs($this->technician)->getJson('/api/maintenance')->assertOk()->json('data'))->pluck('id');
    $history = collect($this->actingAs($this->technician)->getJson('/api/maintenance/history')->assertOk()->json('data'))->pluck('id');

    expect($queue)->toContain($open->uuid)->toContain($held->uuid)
        ->not->toContain($done->uuid)->not->toContain($void->uuid);

    expect($history)->toContain($done->uuid)->toContain($void->uuid)
        ->not->toContain($open->uuid);
});

it('orders the queue overdue first and sinks undated work to the bottom', function () {
    $undated = maintenanceFor($this->technician, overrides: ['scheduled_for' => null]);
    $soon = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->addDays(3)]);
    $overdue = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->subDays(2)]);

    $ids = collect($this->actingAs($this->technician)->getJson('/api/maintenance')->assertOk()->json('data'))
        ->pluck('id')->all();

    // Undated work is not "infinitely urgent" — it has no date, which is a
    // different thing from being due now.
    expect($ids)->toBe([$overdue->uuid, $soon->uuid, $undated->uuid]);
});

it('marks an open dated record overdue once its date has passed', function () {
    $overdue = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->subDay()]);

    $row = collect($this->actingAs($this->technician)->getJson('/api/maintenance')->assertOk()->json('data'))
        ->firstWhere('id', $overdue->uuid);

    expect($row['overdue'])->toBeTrue();
});

it('never marks a completed record overdue, whatever its date', function () {
    $late = maintenanceFor($this->technician, overrides: [
        'scheduled_for' => now()->subMonth(),
        'status' => MaintenanceStatus::Completed->value,
    ]);

    $row = collect($this->actingAs($this->technician)->getJson('/api/maintenance/history')->assertOk()->json('data'))
        ->firstWhere('id', $late->uuid);

    expect($row['overdue'])->toBeFalse();
});

it('lists only dated open work on the scheduled horizon', function () {
    $dated = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->addDays(5)]);
    $undated = maintenanceFor($this->technician, overrides: ['scheduled_for' => null]);
    $done = maintenanceFor($this->technician, overrides: [
        'scheduled_for' => now()->addDays(2),
        'status' => MaintenanceStatus::Completed->value,
    ]);

    $ids = collect($this->actingAs($this->technician)->getJson('/api/maintenance/scheduled')->assertOk()->json('data'))
        ->pluck('id');

    expect($ids)->toContain($dated->uuid)
        ->not->toContain($undated->uuid)
        ->not->toContain($done->uuid);
});

it('honours scope=all on the horizon for an administrator and ignores it for a technician', function () {
    $mine = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->addDay()]);
    $theirs = maintenanceFor($this->otherTechnician, overrides: ['scheduled_for' => now()->addDay()]);

    $adminView = collect(
        $this->actingAs($this->admin)->getJson('/api/maintenance/scheduled?scope=all')->assertOk()->json('data')
    )->pluck('id');

    expect($adminView)->toContain($mine->uuid)->toContain($theirs->uuid);

    // The parameter is accepted from anyone and honoured for nobody who is not
    // an administrator — asking is not the same as being entitled.
    $technicianView = collect(
        $this->actingAs($this->technician)->getJson('/api/maintenance/scheduled?scope=all')->assertOk()->json('data')
    )->pluck('id');

    expect($technicianView)->toContain($mine->uuid)->not->toContain($theirs->uuid);
});

/* ------------------------------------------------------------------- filters */

it('filters the directory by status, type and preventive flag', function () {
    $corrective = maintenanceFor($this->technician, 'corrective', ['status' => MaintenanceStatus::InProgress->value]);
    $preventive = maintenanceFor($this->technician, 'preventive', ['status' => MaintenanceStatus::Scheduled->value]);

    $byStatus = collect($this->actingAs($this->admin)->getJson('/api/admin/maintenance?status=in_progress')->assertOk()->json('data'))->pluck('id');
    expect($byStatus)->toContain($corrective->uuid)->not->toContain($preventive->uuid);

    $byType = collect($this->actingAs($this->admin)->getJson('/api/admin/maintenance?type=preventive')->assertOk()->json('data'))->pluck('id');
    expect($byType)->toContain($preventive->uuid)->not->toContain($corrective->uuid);

    $byFlag = collect($this->actingAs($this->admin)->getJson('/api/admin/maintenance?preventive=1')->assertOk()->json('data'))->pluck('id');
    expect($byFlag)->toContain($preventive->uuid)->not->toContain($corrective->uuid);
});

it('filters the directory by technician', function () {
    $mine = maintenanceFor($this->technician);
    $theirs = maintenanceFor($this->otherTechnician);

    $ids = collect(
        $this->actingAs($this->admin)->getJson("/api/admin/maintenance?technician={$this->technician->uuid}")->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->uuid)->not->toContain($theirs->uuid);
});

it('filters by the location of the target, whether that target is a PC or an asset', function () {
    $building = Building::factory()->create();
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id]);

    $pc = PcUnit::factory()->create(['room_id' => $room->id]);
    $asset = Asset::factory()->create(['current_room_id' => $room->id]);

    $onPc = maintenanceFor($this->technician, overrides: ['pc_unit_id' => $pc->id]);
    $onAsset = maintenanceFor($this->technician, overrides: ['pc_unit_id' => null, 'asset_id' => $asset->id]);
    $elsewhere = maintenanceFor($this->technician);

    // A record has no room of its own; its location is its target's. Both sides
    // of the target CHECK must answer the same filter.
    $byRoom = collect(
        $this->actingAs($this->admin)->getJson("/api/admin/maintenance?room={$room->uuid}")->assertOk()->json('data')
    )->pluck('id');

    expect($byRoom)->toContain($onPc->uuid)->toContain($onAsset->uuid)->not->toContain($elsewhere->uuid);

    $byBuilding = collect(
        $this->actingAs($this->admin)->getJson("/api/admin/maintenance?building={$building->uuid}")->assertOk()->json('data')
    )->pluck('id');

    expect($byBuilding)->toContain($onPc->uuid)->toContain($onAsset->uuid);
});

it('filters to overdue open work only', function () {
    $overdue = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->subWeek()]);
    $future = maintenanceFor($this->technician, overrides: ['scheduled_for' => now()->addWeek()]);
    $lateButDone = maintenanceFor($this->technician, overrides: [
        'scheduled_for' => now()->subWeek(),
        'status' => MaintenanceStatus::Completed->value,
    ]);

    $ids = collect($this->actingAs($this->admin)->getJson('/api/admin/maintenance?overdue=1')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($overdue->uuid)
        ->not->toContain($future->uuid)
        // Finished work cannot be overdue; it is finished.
        ->not->toContain($lateButDone->uuid);
});

it('searches title, diagnosis, root cause and resolution', function () {
    $hit = maintenanceFor($this->technician, overrides: ['title' => 'Replace failing PSU', 'diagnosis' => null]);
    $viaDiagnosis = maintenanceFor($this->technician, overrides: ['title' => 'Routine check', 'diagnosis' => 'Suspected PSU fault']);
    $miss = maintenanceFor($this->technician, overrides: ['title' => 'Clean intake fans', 'diagnosis' => null, 'resolution' => null, 'root_cause' => null]);

    $ids = collect($this->actingAs($this->admin)->getJson('/api/admin/maintenance?search=PSU')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($hit->uuid)->toContain($viaDiagnosis->uuid)->not->toContain($miss->uuid);
});

it('treats a wildcard in the search term as a literal character', function () {
    maintenanceFor($this->technician, overrides: ['title' => 'Ordinary visit']);
    $literal = maintenanceFor($this->technician, overrides: ['title' => 'Disk 100% full']);

    $ids = collect($this->actingAs($this->admin)->getJson('/api/admin/maintenance?search=100%25')->assertOk()->json('data'))->pluck('id');

    // An unescaped `%` would match every row; escaped, it matches the one row
    // that genuinely contains it.
    expect($ids)->toHaveCount(1)->toContain($literal->uuid);
});

/* -------------------------------------------------------------- 422 boundaries */

it('rejects an unknown status rather than returning an unfiltered list', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/maintenance?status=teapot')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('rejects an unknown maintenance type', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/maintenance?type=nonexistent')
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('rejects a sort key outside the allow-list', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/maintenance?sort=(select 1)')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

it('rejects a scheduled window that ends before it starts', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/maintenance?scheduled_from=2026-09-01&scheduled_to=2026-08-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('scheduled_to');
});

/* ------------------------------------------------------------------ dashboard */

it('reports a dashboard whose every figure matches a database aggregate', function () {
    maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::InProgress->value]);
    maintenanceFor($this->technician, overrides: ['status' => MaintenanceStatus::Scheduled->value, 'scheduled_for' => now()->subDay()]);
    maintenanceFor($this->otherTechnician, overrides: ['status' => MaintenanceStatus::Completed->value, 'completed_at' => now()]);

    $data = $this->actingAs($this->admin)->getJson('/api/admin/maintenance/dashboard')->assertOk()->json('data');

    expect($data['posture']['open'])->toBe(2)
        ->and($data['posture']['in_progress'])->toBe(1)
        ->and($data['posture']['scheduled'])->toBe(1)
        ->and($data['posture']['overdue'])->toBe(1)
        ->and($data['throughput']['completed'])->toBe(1)
        // The configured cadence, read from system_settings, not a constant.
        ->and($data['cadence']['interval_days'])->toBe(90)
        ->and($data['cadence']['lead_days'])->toBe(7);
});

/* ------------------------------------------------------------- payload shaping */

it('summarises the checklist rather than embedding it in a list row', function () {
    $record = maintenanceFor($this->technician);
    $record->checklists()->create(['item_label' => 'One', 'is_required' => true, 'is_completed' => true]);
    $record->checklists()->create(['item_label' => 'Two', 'is_required' => true, 'is_completed' => false]);

    $row = collect($this->actingAs($this->technician)->getJson('/api/maintenance')->assertOk()->json('data'))
        ->firstWhere('id', $record->uuid);

    expect($row['checklist'])->toBe(['total' => 2, 'completed' => 1])
        ->and($row)->not->toHaveKey('checklist_items');
});

it('names the target machine and where it sits', function () {
    $building = Building::factory()->create(['name' => 'Science Block']);
    $floor = Floor::factory()->create(['building_id' => $building->id]);
    $room = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Lab 2']);
    $pc = PcUnit::factory()->create(['room_id' => $room->id, 'pc_name' => 'LAB2-PC-07', 'unit_code' => 'U-0007']);

    $record = maintenanceFor($this->technician, overrides: ['pc_unit_id' => $pc->id]);

    $row = collect($this->actingAs($this->technician)->getJson('/api/maintenance')->assertOk()->json('data'))
        ->firstWhere('id', $record->uuid);

    expect($row['target'])->toMatchArray([
        'kind' => 'pc_unit',
        'label' => 'LAB2-PC-07',
        'identifier' => 'U-0007',
    ])->and($row['location'])->toBe(['room' => 'Lab 2', 'building' => 'Science Block']);
});
