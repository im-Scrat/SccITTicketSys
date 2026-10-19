<?php

declare(strict_types=1);

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Services\Import\AssetImportRunner;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Saves the next chunk of an import's rows, then continues itself (Phase 2.10
 * R6, SDD DD-98).
 *
 * A chunk is at most `assets.import.chunk_rows` rows or `chunk_seconds`
 * seconds, well inside the bulk worker's timeout, so a long import is many
 * short jobs and a worker killed by a deploy loses at most one row's
 * transaction — `assets:imports-maintain` resumes a stalled import from its
 * heartbeat. Unique *until processing*, so the chunk can queue its own
 * successor while it is still running.
 */
class ImportAssetRowsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue((string) config('assets.import.queue', 'bulk'));
    }

    public function uniqueId(): string
    {
        return 'asset-import-rows:'.$this->importId;
    }

    public function handle(AssetImportRunner $runner): void
    {
        $import = AssetImport::query()->find($this->importId);
        if ($import === null || $import->status !== AssetImportStatus::Importing) {
            return;
        }

        if ($runner->chunk($import)) {
            self::dispatch($this->importId);
        }
    }
}
