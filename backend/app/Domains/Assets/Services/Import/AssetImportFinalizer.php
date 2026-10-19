<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Analytics\Services\AdminDashboardSignal;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetImportOutcome;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportStatus;
use App\Enums\ImportCatalogMatch;
use App\Models\AssetImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes a save and states, once, what it did (Phase 2.10 R6, owner decision
 * D3, SDD DD-98).
 *
 * The outcome is computed here and stored; every surface — the summary, the
 * review slideshow, the history list, the audit row — shows these words and
 * these counts, never a client's own arithmetic:
 *
 *  - **complete** — every row the administrator confirmed was created
 *    (duplicates and exclusions seen in the preview are not held against it);
 *  - **partial** — anything deviated from the confirmed plan: a row failed,
 *    was not attempted, or turned out to be a duplicate only at save time.
 *    Never labelled complete;
 *  - **nothing_new** — every attempted row was a duplicate (a neutral tone:
 *    the register already had them);
 *  - **failed** — nothing was created (a warning tone, never an alarm red);
 *  - **stopped** — the administrator stopped it.
 *
 * Finalising also cuts each row's raw cells down to the mapped columns —
 * unmapped cells were never the register's business to keep (DR-014).
 */
final class AssetImportFinalizer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdminDashboardSignal $dashboard,
    ) {}

    public function finalize(AssetImport $import): void
    {
        $claimed = AssetImport::query()
            ->whereKey($import->getKey())
            ->where('status', AssetImportStatus::Importing->value)
            ->update(['status' => AssetImportStatus::Finished->value, 'finished_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $import->refresh();

        $result = $this->result($import);
        $import->forceFill(['outcome' => $result['outcome'], 'result' => $result])->save();

        $this->stripRaw($import);

        $actor = $import->confirmed_by !== null ? User::query()->find($import->confirmed_by) : null;
        $this->audit->activity(
            ActivityAction::AssetsImported,
            actor: $actor,
            subject: $import,
            properties: [
                'outcome' => $result['outcome'],
                'created_pc_units' => $result['created_pc_units'],
                'created_assets' => $result['created_assets'],
                'skipped_duplicates' => $result['skipped_duplicates'],
                'failed' => $result['failed'],
                'not_attempted' => $result['not_attempted'],
                'catalog_created' => $result['catalog_created'],
            ],
            request: ImportRequestContext::for($import),
            module: 'assets',
            description: $result['headline'],
        );

        if ($result['created'] > 0) {
            $this->dashboard->send('assets.imported', $import->uuid);
        }
    }

    /**
     * The counts and words for an import's save, from its ledger.
     *
     * @return array<string, mixed>
     */
    public function result(AssetImport $import): array
    {
        $row = fn (string $sql, array $bindings = []): int => (int) DB::table('asset_import_rows')
            ->where('asset_import_id', $import->getKey())
            ->whereRaw($sql, $bindings)
            ->count();

        $createdPc = $row('outcome = ? AND created_pc_unit_id IS NOT NULL', [AssetImportRowOutcome::Created->value]);
        $createdAssets = $row('outcome = ? AND created_asset_id IS NOT NULL', [AssetImportRowOutcome::Created->value]);
        $created = $createdPc + $createdAssets;
        $previewDuplicates = $row("outcome = ? AND failure_code = 'duplicate_preview'", [AssetImportRowOutcome::SkippedDuplicate->value]);
        $atSave = $row("outcome = ? AND failure_code = 'duplicate_at_save'", [AssetImportRowOutcome::SkippedDuplicate->value]);
        $failed = $row('outcome = ?', [AssetImportRowOutcome::Failed->value]);
        $notAttempted = $row('outcome = ?', [AssetImportRowOutcome::NotAttempted->value]);
        $excluded = $row('is_excluded = true');
        $rooms = (int) DB::table('asset_import_rows')->where('asset_import_id', $import->getKey())
            ->where('outcome', AssetImportRowOutcome::Created->value)->whereNotNull('created_room_id')
            ->distinct()->count('created_room_id');
        $withoutRoom = $row('outcome = ? AND created_room_id IS NULL', [AssetImportRowOutcome::Created->value]);
        $buildings = (int) DB::table('asset_import_rows')
            ->join('rooms', 'rooms.id', '=', 'asset_import_rows.created_room_id')
            ->join('floors', 'floors.id', '=', 'rooms.floor_id')
            ->where('asset_import_rows.asset_import_id', $import->getKey())
            ->distinct()->count('floors.building_id');
        $catalogCreated = $import->catalogEntries()->whereNotNull('created_model_id')->where('match', '!=', ImportCatalogMatch::Existing->value)->count();
        $duplicates = $previewDuplicates + $atSave;

        $outcome = match (true) {
            $import->stop_requested_at !== null => AssetImportOutcome::Stopped,
            $created === 0 && $duplicates > 0 && $failed === 0 && $notAttempted === 0 => AssetImportOutcome::NothingNew,
            $created === 0 => AssetImportOutcome::Failed,
            $failed === 0 && $notAttempted === 0 && $atSave === 0 => AssetImportOutcome::Complete,
            default => AssetImportOutcome::Partial,
        };

        $attempted = $created + $failed + $notAttempted + $atSave;
        $records = fn (int $n): string => $n === 1 ? '1 record' : "{$n} records";
        $headline = match ($outcome) {
            AssetImportOutcome::Complete => 'Import complete — '.$records($created).' added',
            AssetImportOutcome::Partial => "Partially imported — {$created} of {$attempted} created",
            AssetImportOutcome::NothingNew => "No new records — {$duplicates} ".($duplicates === 1 ? 'duplicate' : 'duplicates').', existing records unchanged',
            AssetImportOutcome::Failed => 'Nothing imported — '.($failed + $notAttempted)." of {$attempted} rows could not be saved",
            AssetImportOutcome::Stopped => "Import stopped — {$created} created",
        };

        $parts = [];
        if ($createdPc > 0) {
            $parts[] = $createdPc === 1 ? '1 PC unit' : "{$createdPc} PC units";
        }
        if ($createdAssets > 0) {
            $parts[] = $createdAssets === 1 ? '1 asset' : "{$createdAssets} assets";
        }
        $detail = $parts === [] ? 'No records were created.' : implode(' and ', $parts).' created'
            .($rooms > 0 ? ' in '.($rooms === 1 ? '1 room' : "{$rooms} rooms").($buildings > 0 ? ' of '.($buildings === 1 ? '1 building' : "{$buildings} buildings") : '') : '')
            .($withoutRoom > 0 ? ", {$withoutRoom} without a room" : '').'.';

        return [
            'outcome' => $outcome->value,
            'tone' => match ($outcome) {
                AssetImportOutcome::Complete => 'success',
                AssetImportOutcome::NothingNew => 'neutral',
                default => 'warning',
            },
            'headline' => $headline,
            'detail' => $detail,
            'created' => $created,
            'created_pc_units' => $createdPc,
            'created_assets' => $createdAssets,
            'skipped_duplicates' => $duplicates,
            'duplicates_at_save' => $atSave,
            'failed' => $failed,
            'not_attempted' => $notAttempted,
            'excluded' => $excluded,
            'rooms' => $rooms,
            'buildings' => $buildings,
            'without_room' => $withoutRoom,
            'catalog_created' => $catalogCreated,
        ];
    }

    /** Keep only the mapped columns' cells. */
    private function stripRaw(AssetImport $import): void
    {
        $keys = array_map('strval', array_keys(array_filter($import->mapping ?? [])));
        if ($keys === []) {
            DB::table('asset_import_rows')->where('asset_import_id', $import->getKey())->update(['raw' => null, 'cell_flags' => null]);

            return;
        }

        $list = '{'.implode(',', $keys).'}';
        DB::update(
            'UPDATE asset_import_rows SET
                raw = (SELECT jsonb_object_agg(key, value) FROM jsonb_each(raw) WHERE key = ANY(?::text[])),
                cell_flags = NULL
             WHERE asset_import_id = ? AND raw IS NOT NULL',
            [$list, $import->getKey()],
        );
    }
}
