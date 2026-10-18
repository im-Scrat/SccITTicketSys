<?php

declare(strict_types=1);

namespace App\Domains\Assets\Console;

use App\Domains\Assets\Jobs\ImportAssetRowsJob;
use App\Domains\Assets\Jobs\RunAssetImportJob;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Asset-import housekeeping, every 15 minutes (Phase 2.10 R6, SDD DD-96;
 * DR-014).
 *
 *  - **Resume** a save whose worker died: an importing import whose heartbeat
 *    is older than `stall_minutes` gets its next chunk queued again (the
 *    row-level claim makes a duplicate chunk harmless), and a queued import
 *    whose start never ran is started.
 *  - **Expire** drafts nobody confirmed within `draft_days`: rows, decisions
 *    and file deleted, the import kept as the record that it was started.
 *  - **Delete source files** `file_days` after an import finished, and with
 *    them the raw cells and corrections — the register now holds the data;
 *    the ledger keeps what was created, never the spreadsheet itself.
 */
class MaintainAssetImports extends Command
{
    protected $signature = 'assets:imports-maintain';

    protected $description = 'Resume stalled asset imports, expire old drafts and delete old import files';

    public function handle(): int
    {
        $limits = config('assets.import');
        $stalled = now()->subMinutes((int) $limits['stall_minutes']);

        $resumed = 0;
        foreach (AssetImport::query()->where('status', AssetImportStatus::Importing->value)
            ->where(fn ($q) => $q->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $stalled))->get() as $import) {
            ImportAssetRowsJob::dispatch($import->getKey());
            $resumed++;
        }
        foreach (AssetImport::query()->where('status', AssetImportStatus::Queued->value)
            ->where('confirmed_at', '<', $stalled)->get() as $import) {
            RunAssetImportJob::dispatch($import->getKey());
            $resumed++;
        }

        $expired = 0;
        AssetImport::query()
            ->whereIn('status', [AssetImportStatus::Reading->value, AssetImportStatus::Mapping->value, AssetImportStatus::Validating->value, AssetImportStatus::Review->value, AssetImportStatus::Unreadable->value])
            ->where('expires_at', '<=', now())
            ->whereDoesntHave('rows', fn ($q) => $q->where('outcome', 'created'))
            ->orderBy('id')
            ->each(function (AssetImport $import) use (&$expired): void {
                DB::transaction(function () use ($import): void {
                    $import->rows()->delete();
                    $import->locations()->delete();
                    $import->catalogEntries()->delete();
                    $this->deleteFile($import);
                    $import->forceFill(['status' => AssetImportStatus::Expired, 'expires_at' => null])->save();
                });
                $expired++;
            });

        $files = 0;
        AssetImport::query()
            ->where('status', AssetImportStatus::Finished->value)
            ->whereNull('file_deleted_at')
            ->where('finished_at', '<=', now()->subDays((int) $limits['file_days']))
            ->orderBy('id')
            ->each(function (AssetImport $import) use (&$files): void {
                DB::transaction(function () use ($import): void {
                    $this->deleteFile($import);
                    $import->rows()->update(['raw' => null, 'cell_flags' => null, 'corrections' => null]);
                    $import->save();
                });
                $files++;
            });

        $this->info("Resumed {$resumed}, expired {$expired} drafts, deleted {$files} files.");

        return self::SUCCESS;
    }

    private function deleteFile(AssetImport $import): void
    {
        if ($import->path !== null && $import->file_deleted_at === null) {
            Storage::disk($import->disk)->deleteDirectory(dirname($import->path));
        }
        $import->file_deleted_at ??= now();
    }
}
