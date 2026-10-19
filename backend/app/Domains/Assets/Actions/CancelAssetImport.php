<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Exceptions\AssetImportConflict;
use App\Domains\Assets\Services\Import\AssetImportFinalizer;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Cancels a draft, or stops a save (Phase 2.10 R6).
 *
 *  - **A draft** is purged: its rows, decisions and file are deleted, and the
 *    import is kept, marked cancelled, as the record that it was started.
 *  - **A draft reopened by "retry"** already created records, so it is never
 *    purged: the retried rows go back to how the save left them and the import
 *    to finished.
 *  - **A save** is asked to stop. It stops after the row it is on — records
 *    already created stay, and the rest are recorded as not attempted.
 */
final class CancelAssetImport
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssetImportFinalizer $finalizer,
    ) {}

    public function handle(AssetImport $import, User $actor, Request $request): AssetImport
    {
        $import->refresh();

        if ($import->status->isRunning()) {
            if ($import->stop_requested_at === null) {
                $import->forceFill(['stop_requested_at' => now(), 'stopped_by' => $actor->getKey()])->save();
                $this->record($import, $actor, $request, 'stop');
            }

            return $import;
        }

        if (! $import->status->isDraft()) {
            throw new AssetImportConflict('wrong_stage', 'This import has already finished.');
        }

        DB::transaction(function () use ($import): void {
            if ($import->rows()->where('outcome', AssetImportRowOutcome::Created->value)->exists()) {
                $import->rows()->whereNull('outcome')->whereNotNull('previous_failure_code')->update([
                    'outcome' => DB::raw("CASE WHEN previous_failure_code IN ('stopped', 'actor_not_authorised', 'not_ready') THEN 'not_attempted' ELSE 'failed' END"),
                    'failure_code' => DB::raw('previous_failure_code'),
                    'previous_failure_code' => null,
                ]);
                $import->forceFill(['status' => AssetImportStatus::Finished])->save();
                $import->increment('revision');
                // Back to the verdict the last save earned.
                $result = $this->finalizer->result($import);
                $import->forceFill(['outcome' => $result['outcome'], 'result' => $result])->save();

                return;
            }

            $import->rows()->delete();
            $import->locations()->delete();
            $import->catalogEntries()->delete();
            if ($import->hasFile()) {
                Storage::disk($import->disk)->deleteDirectory(dirname((string) $import->path));
            }
            $import->forceFill([
                'status' => AssetImportStatus::Cancelled,
                'file_deleted_at' => now(),
                'expires_at' => null,
            ])->save();
            $import->increment('revision');
        });

        $this->record($import, $actor, $request, 'cancel');

        return $import->refresh();
    }

    private function record(AssetImport $import, User $actor, Request $request, string $kind): void
    {
        $this->audit->activity(
            ActivityAction::AssetImportCancelled,
            actor: $actor,
            subject: $import,
            properties: ['import' => $import->uuid, 'kind' => $kind],
            request: $request,
            module: 'assets',
            description: $kind === 'stop' ? 'Asset import stop requested' : 'Asset import cancelled',
        );
    }
}
