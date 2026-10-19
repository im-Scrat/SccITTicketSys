<?php

declare(strict_types=1);

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Services\Import\AssetImportRunner;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Starts the save of a confirmed import (Phase 2.10 R6): re-checks the
 * confirming administrator, creates the approved catalog entries, then hands
 * the rows to {@see ImportAssetRowsJob}.
 */
class RunAssetImportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue((string) config('assets.import.queue', 'bulk'));
    }

    public function uniqueId(): string
    {
        return 'asset-import-run:'.$this->importId;
    }

    public function handle(AssetImportRunner $runner): void
    {
        $import = AssetImport::query()->find($this->importId);
        if ($import === null || $import->status !== AssetImportStatus::Queued) {
            return;
        }

        $runner->start($import);
    }
}
