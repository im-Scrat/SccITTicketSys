<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\AssetImportOutcome;
use App\Enums\AssetImportStatus;
use App\Models\ActivityLog;
use App\Models\AssetImport;
use App\Models\PcUnit;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssetImportFixtures;

/*
 * Phase 2.10 R6 — an import end to end (SRS FR-AST-016, SDD DD-96/DD-98):
 * upload → read → map → review → confirm → per-row save → one outcome.
 */

beforeEach(function (): void {
    seedRbac();
    Storage::fake('local');
    $this->admin = userWithRole('administrator');
    $this->estate = AssetImportFixtures::estate();
});

it('imports a template file straight through to a complete outcome', function (): void {
    $file = AssetImportFixtures::csv([
        ['Unit code', 'Name', 'Serial number', 'Status', 'Room code', 'RAM'],
        ['lab1-pc01', 'Lab 1 PC 1', 'SN-001', 'Online', 'LAB-1', '16 GB'],
        ['LAB1-PC02', 'Lab 1 PC 2', 'SN-002', 'Available', 'lab-1', '8 GB'],
        ['LAB2-PC01', 'Lab 2 PC 1', 'SN-003', 'Offline', 'LAB-2', ''],
    ]);

    $import = AssetImportFixtures::upload($this->admin, $file);

    // Every column is the template's and every value is known: checked at once.
    expect($import->refresh()->status)->toBe(AssetImportStatus::Review)
        ->and($import->row_counts['ready'])->toBe(3);

    $plan = $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}")
        ->assertOk()->json('data.plan');
    expect($plan)->toMatchArray(['pc_units' => 3, 'assets' => 0, 'rooms' => 2, 'without_room' => 0, 'blocking' => 0]);

    AssetImportFixtures::confirm($this->admin, $import)->assertStatus(202);

    $import->refresh();
    expect($import->status)->toBe(AssetImportStatus::Finished)
        ->and($import->outcome)->toBe(AssetImportOutcome::Complete)
        ->and($import->result['headline'])->toBe('Import complete — 3 records added')
        ->and($import->result['detail'])->toBe('3 PC units created in 2 rooms of 1 building.');

    $pc = PcUnit::query()->where('unit_code', 'LAB1-PC01')->firstOrFail();
    expect($pc->room_id)->toBe($this->estate['lab1']->id)
        ->and($pc->status->value)->toBe('online')
        ->and($pc->specification->ram)->toBe('16 GB');

    // The record's own audit row names the import it came from.
    $log = ActivityLog::query()->where('action', ActivityAction::PcUnitCreated->value)->where('subject_id', $pc->id)->firstOrFail();
    expect($log->properties['import']['uuid'])->toBe($import->uuid)
        ->and($log->properties['import']['row'])->toBe(2);

    expect(ActivityLog::query()->where('action', ActivityAction::AssetsImported->value)->count())->toBe(1);

    $row = $import->rows()->where('row_number', 2)->firstOrFail();
    expect($row->created_pc_unit_id)->toBe($pc->id)
        ->and($row->created_room_id)->toBe($this->estate['lab1']->id)
        ->and($row->applied_fields)->toContain('unit_code', 'pc_name', 'status', 'specification.ram', 'room');
});

it('reads a workbook with a title row above its headers and maps by synonyms', function (): void {
    $file = AssetImportFixtures::xlsxRows([
        ['School inventory 2026'],
        [],
        ['PC No.', 'Computer Name', 'S/N', 'Lab'],
        ['PC-01', 'Front desk', 'X1', 'LAB-1'],
    ]);

    $import = AssetImportFixtures::upload($this->admin, $file);
    $import->refresh();

    expect($import->status)->toBe(AssetImportStatus::Mapping)
        ->and($import->source_meta['header_row'])->toBe(3)
        ->and($import->total_rows)->toBe(1);

    $columns = collect($import->headers)->keyBy('header');
    expect($columns['PC No.']['target'])->toBe('unit_code')
        ->and($columns['S/N']['target'])->toBe('serial_number')
        ->and($columns['Lab']['target'])->toBe('room')
        // "Computer name" could be the PC's name or its hostname.
        ->and($columns['Computer Name']['target'])->toBeNull()
        ->and($columns['Computer Name']['confidence'])->toBe('ambiguous');

    // The name is required: the mapping is refused until a column is chosen.
    AssetImportFixtures::map($this->admin, $import)->assertUnprocessable()->assertJsonValidationErrors('mapping');

    AssetImportFixtures::map($this->admin, $import, [(string) $columns['Computer Name']['index'] => 'name'])->assertOk();
    expect($import->refresh()->status)->toBe(AssetImportStatus::Review);
});

it('lists, shows and serves the history', function (): void {
    $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv([['Unit code', 'Name'], ['PC-9', 'Spare']]));

    $this->actingAs($this->admin)->getJson('/api/admin/asset-imports')
        ->assertOk()->assertJsonPath('data.0.id', $import->uuid);
    $this->actingAs($this->admin)->get("/api/admin/asset-imports/{$import->uuid}/file")
        ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=asset-import-'.$import->uuid.'.csv');
    expect(ActivityLog::query()->where('action', ActivityAction::AssetImportFileDownloaded->value)->count())->toBe(1);

    $this->actingAs($this->admin)->get('/api/admin/asset-imports/template?mode=assets')->assertOk();
    expect(AssetImport::query()->count())->toBe(1);
});
