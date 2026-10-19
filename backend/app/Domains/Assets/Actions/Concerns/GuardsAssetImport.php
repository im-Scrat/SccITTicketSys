<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions\Concerns;

use App\Domains\Assets\Exceptions\AssetImportConflict;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;

/**
 * The state checks every import Action shares (Phase 2.10 R6).
 *
 * A change is accepted only in the stage it belongs to — a mapping while the
 * columns are being mapped or reviewed, a correction while rows are under
 * review — and each accepted change moves the import to its next `revision`,
 * which is what makes a stale confirm detectable.
 */
trait GuardsAssetImport
{
    /**
     * @param  list<AssetImportStatus>  $allowed
     */
    protected function assertStatus(AssetImport $import, array $allowed): void
    {
        $import->refresh();
        if (! in_array($import->status, $allowed, true)) {
            throw new AssetImportConflict('wrong_stage', match ($import->status) {
                AssetImportStatus::Reading, AssetImportStatus::Validating => 'This import is still being checked. Wait a moment and try again.',
                AssetImportStatus::Queued, AssetImportStatus::Importing => 'This import is already being saved.',
                AssetImportStatus::Finished => 'This import has finished.',
                AssetImportStatus::Cancelled, AssetImportStatus::Expired => 'This import is no longer open.',
                default => 'This import cannot be changed at this step.',
            });
        }
    }

    protected function nextRevision(AssetImport $import): int
    {
        $import->increment('revision');

        return $import->revision;
    }
}
