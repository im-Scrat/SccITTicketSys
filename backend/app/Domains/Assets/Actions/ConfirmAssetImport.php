<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Exceptions\AssetImportConflict;
use App\Domains\Assets\Jobs\RunAssetImportJob;
use App\Domains\Assets\Services\Import\AssetImportPlanner;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Confirms an import and queues its save (Phase 2.10 R6, owner decision D3).
 *
 * The request carries the revision and the counts the administrator was
 * shown. A different revision, or a plan whose counts differ, is refused with
 * 409 `plan_changed` and the plan as it is now — another administrator may
 * have corrected a row in the meantime, and saving a plan nobody saw is the
 * one thing this step must never do. A row still needing a decision is 422. A
 * repeated confirm of the same revision is idempotent: it returns the import
 * already queued (202) rather than queuing a second save.
 *
 * The confirming administrator's address and browser are captured here, so
 * the audit rows the worker writes later still name them.
 */
final class ConfirmAssetImport
{
    public function __construct(private readonly AssetImportPlanner $planner) {}

    /**
     * @param  array{pc_units: int, assets: int, catalog_entries: int}  $expected
     * @return array{import: AssetImport, repeated: bool}
     */
    public function handle(AssetImport $import, int $revision, array $expected, User $actor, Request $request): array
    {
        $import->refresh();

        if (($import->status->isRunning() || $import->status === AssetImportStatus::Finished)
            && $import->confirmed_at !== null && ($import->plan['revision'] ?? null) === $revision) {
            return ['import' => $import, 'repeated' => true];
        }

        if ($import->status !== AssetImportStatus::Review) {
            throw new AssetImportConflict('wrong_stage', 'This import cannot be confirmed at this step.');
        }

        $plan = $this->planner->plan($import);
        if ($import->revision !== $revision
            || $plan['pc_units'] !== $expected['pc_units']
            || $plan['assets'] !== $expected['assets']
            || count($plan['catalog_entries']) !== $expected['catalog_entries']) {
            throw AssetImportConflict::planChanged($plan);
        }

        if ($plan['blocking'] > 0) {
            throw ValidationException::withMessages(['import' => $plan['blocking'] === 1
                ? '1 row still needs a decision. Fix it or exclude it first.'
                : "{$plan['blocking']} rows still need a decision. Fix them or exclude them first."]);
        }
        if ($plan['total'] === 0) {
            throw ValidationException::withMessages(['import' => 'There is nothing to import: every row is a duplicate or excluded.']);
        }

        $updated = AssetImport::query()->whereKey($import->getKey())
            ->where('status', AssetImportStatus::Review->value)
            ->where('revision', $revision)
            ->update([
                'status' => AssetImportStatus::Queued->value,
                'plan' => json_encode($plan),
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'confirm_ip' => $request->ip(),
                'confirm_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'stop_requested_at' => null,
                'stopped_by' => null,
                'expires_at' => null,
                'updated_at' => now(),
            ]);
        if ($updated === 0) {
            throw AssetImportConflict::planChanged($this->planner->plan($import->refresh()));
        }

        RunAssetImportJob::dispatch($import->getKey());

        return ['import' => $import->refresh(), 'repeated' => false];
    }
}
