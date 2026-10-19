<?php

declare(strict_types=1);

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Services\Import\AssetImportReader;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Reads an uploaded import file into rows (Phase 2.10 R6). On the `bulk`
 * queue, like every import step, so a large workbook never sits in front of
 * notifications.
 */
class ReadAssetImportFileJob implements ShouldBeUnique, ShouldQueue
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
        return 'asset-import-read:'.$this->importId;
    }

    public function handle(AssetImportReader $reader): void
    {
        $import = AssetImport::query()->find($this->importId);
        if ($import === null || $import->status !== AssetImportStatus::Reading) {
            return;
        }

        $reader->read($import);
    }

    public function failed(?Throwable $exception): void
    {
        AssetImport::query()->whereKey($this->importId)
            ->where('status', AssetImportStatus::Reading->value)
            ->update(['status' => AssetImportStatus::Unreadable->value, 'error_code' => 'worker_failed', 'updated_at' => now()]);
    }
}
