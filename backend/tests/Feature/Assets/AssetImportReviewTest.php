<?php

declare(strict_types=1);

use App\Enums\AssetImportRowStatus;
use App\Enums\AssetImportStatus;
use App\Enums\ComponentType;
use App\Enums\ImportCatalogMatch;
use App\Enums\ImportLocationMatch;
use App\Enums\ImportLocationResolution;
use App\Models\Asset;
use App\Models\AssetImport;
use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use App\Models\Manufacturer;
use App\Models\PcUnit;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssetImportFixtures;

/*
 * Phase 2.10 R6 — checking rows before anything is saved (SDD DD-96/DD-97,
 * owner decision D5).
 *
 * Duplicates are compared case-insensitively and include archived records;
 * a location is applied without a decision only on an exact code or an exact
 * building + floor + room; a catalog combination not in the catalog is a
 * proposal and nothing is written to the catalog before confirm; a
 * correction is checked again and resolves what it fixes on every row
 * involved.
 */

beforeEach(function (): void {
    seedRbac();
    Storage::fake('local');
    $this->admin = userWithRole('administrator');
    $this->estate = AssetImportFixtures::estate();

    $this->review = function (array $rows, string $mode = 'pc_units', array $overrides = [], array $extra = []): AssetImport {
        $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv($rows), $mode);
        if ($import->refresh()->status === AssetImportStatus::Mapping) {
            AssetImportFixtures::map($this->admin, $import, $overrides, $extra)->assertOk();
        }

        return $import->refresh();
    };
    $this->row = fn (AssetImport $import, int $number) => $import->rows()->where('row_number', $number)->firstOrFail();
    $this->codes = fn (AssetImport $import, int $number): array => array_column(($this->row)($import, $number)->issues ?? [], 'code');
});

/* --------------------------------------------------------------- duplicates */

it('skips a row whose unit code exists in any case, or only archived', function (): void {
    PcUnit::factory()->create(['unit_code' => 'LAB1-PC01']);
    PcUnit::factory()->create(['unit_code' => 'OLD-PC9'])->delete();

    $import = ($this->review)([
        ['Unit code', 'Name', 'Room code'],
        ['lab1-pc01', 'Again', 'LAB-1'],
        ['old-pc9', 'Archived', 'LAB-1'],
        ['NEW-PC1', 'New', 'LAB-1'],
    ]);

    expect(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::Duplicate)
        ->and(($this->codes)($import, 2))->toContain('duplicate_existing')
        ->and(($this->codes)($import, 3))->toContain('duplicate_archived')
        ->and(($this->row)($import, 4)->status)->toBe(AssetImportRowStatus::Ready);
});

it('skips an exact repeat inside the file, and blocks rows that share an identifier but differ', function (): void {
    $import = ($this->review)([
        ['Unit code', 'Name', 'Room code'],
        ['PC-1', 'One', 'LAB-1'],
        ['pc-1', 'One', 'LAB-1'],
        ['PC-2', 'Two', 'LAB-1'],
        ['PC-2', 'Two again', 'LAB-1'],
    ]);

    expect(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::Ready)
        ->and(($this->codes)($import, 3))->toBe(['repeat_in_file'])
        ->and(($this->row)($import, 4)->status)->toBe(AssetImportRowStatus::Invalid)
        ->and(($this->row)($import, 5)->status)->toBe(AssetImportRowStatus::Invalid);

    // Excluding one of the clashing rows clears the other.
    $this->actingAs($this->admin)->patchJson("/api/admin/asset-imports/{$import->uuid}/rows/5", ['exclude' => true])->assertOk();
    expect(($this->row)($import, 4)->status)->toBe(AssetImportRowStatus::Ready)
        ->and(($this->row)($import, 5)->status)->toBe(AssetImportRowStatus::Excluded);
});

it('holds for review a PC asset tag that is an asset\'s, and a repeated serial number', function (): void {
    Asset::factory()->create(['asset_tag' => 'TAG-77', 'serial_number' => 'SER-1']);

    $import = ($this->review)([
        ['Unit code', 'Name', 'Asset tag', 'Serial number', 'Room code'],
        ['PC-1', 'One', 'tag-77', 'ser-1', 'LAB-1'],
    ]);

    expect(($this->codes)($import, 2))->toContain('cross_table_identifier', 'possible_duplicate_serial')
        ->and(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::NeedsReview);

    $this->actingAs($this->admin)->patchJson("/api/admin/asset-imports/{$import->uuid}/rows/2", [
        'acknowledge' => ['cross_table_identifier', 'possible_duplicate_serial', 'duplicate_existing'],
    ])->assertOk();

    expect(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::Ready)
        // A duplicate can never be acknowledged away.
        ->and(($this->row)($import, 2)->acknowledged)->not->toContain('duplicate_existing');
});

/* ---------------------------------------------------------------- locations */

it('applies an exact room code in any case, and an exact building + floor + room', function (): void {
    $import = ($this->review)([
        ['Unit code', 'Name', 'Building', 'Floor', 'Room', 'Room code'],
        ['PC-1', 'One', '', '', '', 'lab-1'],
        ['PC-2', 'Two', 'Main Building', '1st floor', 'Computer Lab 2', ''],
        ['PC-3', 'Three', 'MAIN', 'Floor 1', 'computer lab 2', ''],
    ]);

    $locations = $import->locations()->get();
    expect($locations)->toHaveCount(3)
        ->and($locations->every(fn ($l) => $l->match === ImportLocationMatch::Matched))->toBeTrue()
        ->and($import->row_counts['ready'])->toBe(3);
});

it('never applies a room name alone, a contradiction or a combined text', function (array $row, ImportLocationMatch $match): void {
    $import = ($this->review)([['Unit code', 'Name', 'Building', 'Room', 'Room code', 'Location'], ['PC-1', 'One', ...$row]]);
    $location = $import->locations()->firstOrFail();

    expect($location->match)->toBe($match)
        ->and($location->resolution)->toBe(ImportLocationResolution::Pending)
        ->and(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::NeedsReview);
})->with([
    'a unique room name alone' => [['', 'Computer Lab 1', '', ''], ImportLocationMatch::Ambiguous],
    'a code contradicted by its building' => [['Annex', '', 'LAB-1', ''], ImportLocationMatch::Ambiguous],
    'a combined text' => [['', '', '', 'Main Building, LAB-1 corner'], ImportLocationMatch::Ambiguous],
    'nothing like it' => [['', 'Gymnasium', '', ''], ImportLocationMatch::Unmatched],
    'a room not in use' => [['', '', 'OLD-1', ''], ImportLocationMatch::NotSelectable],
]);

it('applies one decision to every row with the same location text', function (): void {
    $import = ($this->review)([
        ['Unit code', 'Name', 'Room'],
        ['PC-1', 'One', 'Comp Lab'],
        ['PC-2', 'Two', 'comp lab'],
        ['PC-3', 'Three', ''],
    ]);
    $location = $import->locations()->where('normalized', '!=', '')->firstOrFail();
    expect($location->row_count)->toBe(2)
        ->and(($this->codes)($import, 4))->toContain('location_blank');

    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/locations/{$location->uuid}", [
        'resolution' => 'room', 'room' => $this->estate['lab2']->uuid,
    ])->assertOk();
    $blank = $import->locations()->where('normalized', '')->firstOrFail();
    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/locations/{$blank->uuid}", ['resolution' => 'unassigned'])->assertOk();

    expect($import->refresh()->row_counts['ready'])->toBe(3);
    $plan = $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}")->json('data.plan');
    expect($plan)->toMatchArray(['rooms' => 1, 'without_room' => 1]);
});

it('assigns a room not in use only when asked to assign it anyway', function (): void {
    $import = ($this->review)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'OLD-1']]);
    $location = $import->locations()->firstOrFail();
    $uri = "/api/admin/asset-imports/{$import->uuid}/locations/{$location->uuid}";

    $this->actingAs($this->admin)->putJson($uri, ['resolution' => 'room', 'room' => $this->estate['old']->uuid])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson($uri, ['resolution' => 'room', 'room' => $this->estate['old']->uuid, 'assign_anyway' => true])->assertOk();

    expect(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::Ready);
});

it('never offers or accepts an archived room', function (): void {
    $this->estate['lab2']->delete();
    $import = ($this->review)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-2']]);

    expect($import->locations()->firstOrFail()->match)->not->toBe(ImportLocationMatch::Matched);
    $this->actingAs($this->admin)->getJson('/api/admin/asset-imports/room-options?search=lab')
        ->assertOk()->assertJsonMissing(['code' => 'LAB-2']);
    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/locations/{$import->locations()->first()->uuid}", [
        'resolution' => 'room', 'room' => $this->estate['lab2']->uuid,
    ])->assertUnprocessable();
});

/* ------------------------------------------------------------------ catalog */

it('proposes new catalog entries, matches existing ones, and writes nothing to the catalog', function (): void {
    $epson = Manufacturer::factory()->create(['name' => 'Epson']);
    $component = HardwareComponent::factory()->create(['component_type' => ComponentType::Printer, 'manufacturer_id' => $epson->id, 'name' => 'Printer']);
    HardwareModel::factory()->create(['hardware_component_id' => $component->id, 'model_name' => 'L3210']);
    $before = [Manufacturer::query()->count(), HardwareComponent::query()->count(), HardwareModel::query()->count()];

    $import = ($this->review)([
        ['Asset tag', 'Category', 'Brand', 'Model', 'Room code'],
        ['A-1', 'Printer', 'EPSON', 'l3210', 'LAB-1'],
        ['A-2', 'Projector', 'BenQ', 'MX550', 'LAB-1'],
        ['A-3', 'Printer', '', 'L3210', 'LAB-1'],
    ], 'assets');

    $entries = $import->catalogEntries()->get()->keyBy('model_name');
    expect($entries['l3210']->match)->toBe(ImportCatalogMatch::Existing)
        ->and($entries['MX550']->match)->toBe(ImportCatalogMatch::New)
        ->and($entries['L3210']->match)->toBe(ImportCatalogMatch::Suggested)
        ->and(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::Ready)
        ->and(($this->codes)($import, 3))->toContain('catalog_pending')
        ->and(($this->codes)($import, 4))->toContain('catalog_ambiguous')
        ->and([Manufacturer::query()->count(), HardwareComponent::query()->count(), HardwareModel::query()->count()])->toBe($before);

    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/catalog/{$entries['MX550']->uuid}", ['decision' => 'approved'])->assertOk();
    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/catalog/{$entries['L3210']->uuid}", [
        'decision' => 'existing', 'model' => $entries['l3210']->matched_model_id,
    ])->assertOk();

    expect($import->refresh()->row_counts['ready'])->toBe(3)
        ->and(HardwareModel::query()->count())->toBe($before[2]);
});

it('blocks a proposal that collides with an archived catalog entry', function (): void {
    $acer = Manufacturer::factory()->create(['name' => 'Acer']);
    $component = HardwareComponent::factory()->create(['component_type' => ComponentType::Monitor, 'manufacturer_id' => $acer->id, 'name' => 'Monitor']);
    HardwareModel::factory()->create(['hardware_component_id' => $component->id, 'model_name' => 'V196'])->delete();

    $import = ($this->review)([['Asset tag', 'Category', 'Brand', 'Model'], ['A-1', 'Monitor', 'Acer', 'V196']], 'assets');
    $entry = $import->catalogEntries()->firstOrFail();

    expect($entry->match)->toBe(ImportCatalogMatch::Blocked);
    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/catalog/{$entry->uuid}", ['decision' => 'approved'])
        ->assertUnprocessable()->assertJsonValidationErrors('decision');
});

it('refuses an existing model of another category', function (): void {
    $model = HardwareModel::factory()->create();
    $import = ($this->review)([['Asset tag', 'Category', 'Model'], ['A-1', 'Scanner', 'DS-1']], 'assets');
    $entry = $import->catalogEntries()->firstOrFail();
    $model->component->update(['component_type' => ComponentType::Mouse]);

    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/catalog/{$entry->uuid}", ['decision' => 'existing', 'model' => $model->id])
        ->assertUnprocessable()->assertJsonValidationErrors('model');
});

/* ------------------------------------------------------- values and checks */

it('holds rows with a value that has no meaning, until one is chosen', function (): void {
    $import = ($this->review)([['Unit code', 'Name', 'Status', 'Room code'], ['PC-1', 'One', 'Workng', 'LAB-1'], ['PC-2', 'Two', 'Online', 'LAB-1']]);

    expect(($this->codes)($import, 2))->toContain('unknown_value')
        ->and(($this->row)($import, 3)->status)->toBe(AssetImportRowStatus::Ready);

    $column = collect($import->headers)->firstWhere('header', 'Status')['index'];
    $meaning = $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}/values?column={$column}&target=status")
        ->assertOk()->json('data.0');
    expect($meaning['field'])->toBe('status.pc_unit')
        ->and($meaning['values'][0]['raw'])->toBe('Workng');

    AssetImportFixtures::map($this->admin, $import, [], ['value_mappings' => ['status.pc_unit' => ['workng' => 'available', 'online' => 'available']]])->assertOk();
    expect(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::Ready);
});

it('asks for a date order only when a date could be read either way', function (): void {
    $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv([
        ['Unit code', 'Name', 'Purchase date', 'Room code'],
        ['PC-1', 'One', '05/03/2024', 'LAB-1'],
    ]));

    AssetImportFixtures::map($this->admin, $import)->assertUnprocessable()->assertJsonValidationErrors('date_order');
    AssetImportFixtures::map($this->admin, $import, [], ['date_order' => 'dmy'])->assertOk();

    expect(($this->row)($import, 2)->payload['purchase_date'])->toBe('2024-03-05');
});

it('applies the shared create rules: required, formats, lengths, warranty after purchase', function (): void {
    $import = ($this->review)([
        ['Unit code', 'Name', 'IP address', 'MAC address', 'Purchase date', 'Warranty expiration', 'Room code'],
        ['PC-1', '', '999.1.1.1', '001B44113AB7', '2024-05-01', '2023-01-01', 'LAB-1'],
    ]);
    $row = ($this->row)($import, 2);
    $codes = ($this->codes)($import, 2);

    expect($row->status)->toBe(AssetImportRowStatus::Invalid)
        ->and($codes)->toContain('required_missing', 'invalid_format', 'warranty_before_purchase', 'mac_reformatted')
        ->and($row->payload['mac_address'])->toBe('00:1B:44:11:3A:B7');
});

it('saves a correction beside the original cell and checks the row again', function (): void {
    $import = ($this->review)([['Unit code', 'Name', 'Room code'], ['PC-1', '', 'LAB-1']]);
    expect(($this->row)($import, 2)->status)->toBe(AssetImportRowStatus::Invalid);

    $response = $this->actingAs($this->admin)->patchJson("/api/admin/asset-imports/{$import->uuid}/rows/2", [
        'corrections' => ['name' => 'Front desk PC'],
    ])->assertOk();

    expect($response->json('data.status'))->toBe('ready')
        ->and($response->json('data.values.pc_name'))->toBe('Front desk PC')
        ->and($response->json('data.sources.pc_name'))->toBe('correction')
        ->and($response->json('data.corrections.name.value'))->toBe('Front desk PC')
        ->and(($this->row)($import, 2)->raw)->not->toHaveKey('1');

    // A row-level room overrides its location text.
    $this->actingAs($this->admin)->patchJson("/api/admin/asset-imports/{$import->uuid}/rows/2", [
        'corrections' => ['room' => ['uuid' => $this->estate['lab2']->uuid]],
    ])->assertOk();
    expect($this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}")->json('data.plan.rooms'))->toBe(1);
});

it('lists rows by status and search, with counts', function (): void {
    $import = ($this->review)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1'], ['PC-2', '', 'LAB-1']]);

    $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}/rows?status=invalid")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.row_number', 3);
    $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}/rows?search=pc-1")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.original.unit_code', 'PC-1');
    expect($import->row_counts)->toMatchArray(['ready' => 1, 'invalid' => 1]);
});

it('refuses edits outside review, and a bulk exclusion counts every row', function (): void {
    $import = ($this->review)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1'], ['PC-2', 'Two', 'LAB-1']]);

    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/rows/bulk", ['rows' => [2, 3], 'exclude' => true])
        ->assertOk()->assertJsonPath('updated', 2);
    expect($import->refresh()->row_counts['excluded'])->toBe(2);

    // Cancelling purges a draft's rows; its mapping can no longer change either.
    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/cancel")->assertOk();
    $this->actingAs($this->admin)->patchJson("/api/admin/asset-imports/{$import->uuid}/rows/2", ['exclude' => false])->assertNotFound();
    AssetImportFixtures::map($this->admin, $import)->assertStatus(409)->assertJsonPath('code', 'wrong_stage');
});
