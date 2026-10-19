<?php

declare(strict_types=1);

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Services\Import\AssetImportValidation;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Checks every row of an import after its mapping changed (Phase 2.10 R6).
 *
 * Carries the revision it was dispatched for: a mapping saved again before
 * this ran makes it stale, and only the newest check moves the import on to
 * review — an older one can never overwrite a newer one's result.
 */
class ValidateAssetImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly int $importId, public readonly int $revision)
    {
        $this->onQueue((string) config('assets.import.queue', 'bulk'));
    }

    public function handle(AssetImportValidation $validation): void
    {
        $import = AssetImport::query()->find($this->importId);
        if ($import === null || $import->status !== AssetImportStatus::Validating || $import->revision !== $this->revision) {
            return;
        }

        $validation->validateAll($import);

        AssetImport::query()->whereKey($import->getKey())
            ->where('status', AssetImportStatus::Validating->value)
            ->where('revision', $this->revision)
            ->update(['status' => AssetImportStatus::Review->value, 'updated_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        // Back to the mapping step, so the administrator can try again.
        AssetImport::query()->whereKey($this->importId)
            ->where('status', AssetImportStatus::Validating->value)
            ->where('revision', $this->revision)
            ->update(['status' => AssetImportStatus::Mapping->value, 'error_code' => 'validation_failed', 'updated_at' => now()]);
    }
}
