<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\Actions\CreateCatalogEntry;
use App\Domains\Assets\Exceptions\CatalogEntryBlocked;
use App\Domains\Assets\Jobs\ImportAssetRowsJob;
use App\Domains\Assets\Services\AssetImportAccess;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportRowStatus;
use App\Enums\AssetImportStatus;
use App\Enums\ImportCatalogDecision;
use App\Enums\ImportCatalogMatch;
use App\Models\AssetImport;
use App\Models\AssetImportCatalogEntry;
use App\Models\AssetImportRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Drives the save of a confirmed import (Phase 2.10 R6, owner decision D3,
 * SDD DD-98).
 *
 * The confirming administrator is re-checked when the save starts **and
 * before every chunk**: an administrator demoted mid-import stops the import
 * at the next chunk boundary, with the records already created kept and the
 * rest recorded as not attempted. A "Stop import" request is honoured the
 * same way.
 */
final class AssetImportRunner
{
    public function __construct(
        private readonly AssetImportAccess $access,
        private readonly CreateCatalogEntry $catalog,
        private readonly AssetImportRowCommitter $committer,
        private readonly AssetImportFinalizer $finalizer,
    ) {}

    public function start(AssetImport $import): void
    {
        $claimed = AssetImport::query()->whereKey($import->getKey())
            ->where('status', AssetImportStatus::Queued->value)
            ->update([
                'status' => AssetImportStatus::Importing->value,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }
        $import->refresh();

        // Duplicates seen in the preview are skipped, and say so.
        $this->pending($import)->where('status', AssetImportRowStatus::Duplicate->value)->update([
            'outcome' => AssetImportRowOutcome::SkippedDuplicate->value,
            'failure_code' => 'duplicate_preview',
            'processed_at' => now(),
        ]);

        $actor = $this->actor($import);
        if ($actor === null) {
            $this->abandon($import, 'actor_not_authorised');

            return;
        }

        $request = ImportRequestContext::for($import);
        $entries = $import->catalogEntries()
            ->where('decision', ImportCatalogDecision::Approved->value)
            ->where('match', '!=', ImportCatalogMatch::Existing->value)
            ->whereNull('created_model_id')
            ->get();

        foreach ($entries as $entry) {
            if (! $this->pendingForEntry($entry)->exists()) {
                continue;
            }
            try {
                $model = $this->catalog->handle(
                    $entry->component_type,
                    $entry->manufacturer_name,
                    $entry->model_name,
                    $entry->model_number,
                    $actor,
                    $request,
                    ImportRequestContext::auditContext($import),
                );
                $entry->forceFill(['created_model_id' => $model->getKey()])->save();
            } catch (CatalogEntryBlocked $e) {
                $this->pendingForEntry($entry)->update([
                    'outcome' => AssetImportRowOutcome::Failed->value,
                    'failure_code' => 'catalog_'.$e->reason,
                    'processed_at' => now(),
                ]);
            }
        }

        ImportAssetRowsJob::dispatch($import->getKey());
    }

    /**
     * Save the next chunk. True when rows remain and another chunk should run.
     */
    public function chunk(AssetImport $import): bool
    {
        $actor = $this->actor($import);
        if ($actor === null) {
            $this->abandon($import, 'actor_not_authorised');

            return false;
        }

        $request = ImportRequestContext::for($import);
        $deadline = microtime(true) + (int) config('assets.import.chunk_seconds', 50);
        $ids = $this->ready($import)->orderBy('row_number')->limit((int) config('assets.import.chunk_rows', 100))->pluck('id');

        foreach ($ids as $id) {
            if ($this->stopRequested($import) || microtime(true) > $deadline) {
                break;
            }
            $this->committer->commit($import, (int) $id, $actor, $request);
        }

        $import->forceFill(['heartbeat_at' => now()])->save();

        if ($this->stopRequested($import)) {
            $this->abandon($import, 'stopped');

            return false;
        }

        if ($this->ready($import)->exists()) {
            return true;
        }

        $this->finalizer->finalize($import);

        return false;
    }

    /** Record every unsaved row as not attempted, and close the import. */
    private function abandon(AssetImport $import, string $reason): void
    {
        $this->ready($import)->update([
            'outcome' => AssetImportRowOutcome::NotAttempted->value,
            'failure_code' => $reason,
            'processed_at' => now(),
        ]);
        $this->finalizer->finalize($import);
    }

    private function actor(AssetImport $import): ?User
    {
        $actor = $import->confirmed_by !== null ? User::query()->find($import->confirmed_by) : null;

        return $actor !== null && $actor->isActive() && $this->access->canUse($actor) ? $actor : null;
    }

    private function stopRequested(AssetImport $import): bool
    {
        return AssetImport::query()->whereKey($import->getKey())->whereNotNull('stop_requested_at')->exists();
    }

    /**
     * @return HasMany<AssetImportRow, AssetImport>
     */
    private function pending(AssetImport $import): HasMany
    {
        return $import->rows()->whereNull('outcome')->where('is_excluded', false);
    }

    /**
     * @return HasMany<AssetImportRow, AssetImport>
     */
    private function ready(AssetImport $import): HasMany
    {
        return $this->pending($import)->where('status', AssetImportRowStatus::Ready->value);
    }

    /**
     * @return HasMany<AssetImportRow, AssetImportCatalogEntry>
     */
    private function pendingForEntry(AssetImportCatalogEntry $entry): HasMany
    {
        return $entry->rows()->whereNull('outcome')->where('is_excluded', false)
            ->where('status', AssetImportRowStatus::Ready->value);
    }
}
