<?php

declare(strict_types=1);

use App\Domains\Assets\Http\Requests\StoreAssetRequest;
use App\Domains\Assets\Http\Requests\StorePcUnitRequest;
use App\Domains\Assets\Rules\AssetCreationRules;
use App\Domains\Assets\Rules\PcUnitCreationRules;
use App\Enums\AssetImportEntityMode;
use App\Enums\AssetImportOutcome;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportRowStatus;
use App\Enums\AssetImportStatus;
use App\Enums\ComponentType;
use App\Enums\ImportCatalogDecision;
use App\Enums\ImportCatalogMatch;
use App\Enums\ImportDateFormat;
use App\Enums\ImportEntityType;
use App\Enums\ImportLocationMatch;
use App\Enums\ImportLocationResolution;
use App\Models\AssetImport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Phase 2.10 R6 — the import ledger's CHECK lists are frozen in their
 * migration as values; these keep each equal to the enum it guards. And the
 * form and the import share one set of create rules — a parity test, so the
 * import can never accept what the form refuses.
 */

dataset('import checks', [
    'mode' => ['asset_imports', 'asset_imports_entity_mode_check', AssetImportEntityMode::values()],
    'status' => ['asset_imports', 'asset_imports_status_check', AssetImportStatus::values()],
    'outcome' => ['asset_imports', 'asset_imports_outcome_check', AssetImportOutcome::values()],
    'date order' => ['asset_imports', 'asset_imports_date_order_check', ImportDateFormat::values()],
    'row status' => ['asset_import_rows', 'asset_import_rows_status_check', AssetImportRowStatus::values()],
    'row outcome' => ['asset_import_rows', 'asset_import_rows_outcome_check', AssetImportRowOutcome::values()],
    'row type' => ['asset_import_rows', 'asset_import_rows_entity_type_check', ImportEntityType::values()],
    'location match' => ['asset_import_locations', 'asset_import_locations_match_check', ImportLocationMatch::values()],
    'location resolution' => ['asset_import_locations', 'asset_import_locations_resolution_check', ImportLocationResolution::values()],
    'catalog match' => ['asset_import_catalog_entries', 'asset_import_catalog_entries_match_check', ImportCatalogMatch::values()],
    'catalog decision' => ['asset_import_catalog_entries', 'asset_import_catalog_entries_decision_check', ImportCatalogDecision::values()],
    'catalog type' => ['asset_import_catalog_entries', 'asset_import_catalog_entries_component_type_check', ComponentType::values()],
]);

it('keeps the CHECK list equal to the enum', function (string $table, string $constraint, array $values): void {
    $definition = (string) DB::table('pg_constraint')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
        ->where('pg_class.relname', $table)
        ->where('pg_constraint.conname', $constraint)
        ->value(DB::raw('pg_get_constraintdef(pg_constraint.oid)'));

    preg_match_all("/'([^']+)'/", $definition, $matches);
    $allowed = $matches[1];
    sort($allowed);
    sort($values);

    expect($allowed)->toBe($values);
})->with('import checks');

it('refuses an excluded flag that disagrees with the status', function (): void {
    $import = AssetImport::factory()->create();

    expect(fn () => DB::table('asset_import_rows')->insert([
        'asset_import_id' => $import->id, 'row_number' => 2, 'status' => 'ready', 'is_excluded' => true,
    ]))->toThrow(QueryException::class, 'asset_import_rows_excluded_check');
});

it('refuses a created row that names no record', function (): void {
    $import = AssetImport::factory()->create();

    expect(fn () => DB::table('asset_import_rows')->insert([
        'asset_import_id' => $import->id, 'row_number' => 2, 'status' => 'ready', 'outcome' => 'created',
    ]))->toThrow(QueryException::class, 'asset_import_rows_created_check');
});

it('gives the create forms and the import the same rules', function (): void {
    expect((new StorePcUnitRequest)->rules())->toEqual(PcUnitCreationRules::rules())
        ->and((new StorePcUnitRequest)->messages())->toBe(PcUnitCreationRules::messages())
        // The asset form narrows status and condition and adds the "Other"
        // fields (DD-114); every other rule is still the import's, verbatim.
        ->and((new StoreAssetRequest)->rules())->toEqual(AssetCreationRules::formRules())
        ->and(collect(AssetCreationRules::formRules())->except(['status', 'condition', 'category', 'category_detail', 'supplier_other'])->all())
        ->toEqual(collect(AssetCreationRules::rules())->except(['status', 'condition'])->all())
        ->and((new StoreAssetRequest)->messages())->toMatchArray(AssetCreationRules::messages());
});
