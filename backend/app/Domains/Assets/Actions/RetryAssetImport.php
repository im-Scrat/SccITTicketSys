<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Exceptions\AssetImportConflict;
use App\Domains\Assets\Services\Import\AssetImportValidation;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reopens a finished import's failed and unattempted rows for another try
 * (Phase 2.10 R6, owner decision D3).
 *
 * Only those rows are checked again; created rows and skipped duplicates are
 * history and stay exactly as they are. Each reopened row keeps the reason it
 * did not save (`previous_failure_code`), so the review shows why it is back.
 * The import returns to review: the administrator fixes what needs fixing and
 * confirms again, and the next save attempts only these rows.
 */
final class RetryAssetImport
{
    public function __construct(
        private readonly AssetImportValidation $validation,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AssetImport $import, User $actor, Request $request): AssetImport
    {
        $import->refresh();

        $retryable = $import->rows()->whereIn('outcome', [AssetImportRowOutcome::Failed->value, AssetImportRowOutcome::NotAttempted->value]);
        if ($import->status !== AssetImportStatus::Finished || ! $retryable->exists()) {
            throw new AssetImportConflict('wrong_stage', 'There are no failed rows to retry.');
        }

        $count = 0;
        DB::transaction(function () use ($import, $retryable, &$count): void {
            $count = $retryable->update([
                'outcome' => null,
                'previous_failure_code' => DB::raw('failure_code'),
                'failure_code' => null,
                'updated_at' => now(),
            ]);

            // The earlier save's verdict no longer describes the import; the
            // next save computes a new one over every row.
            $import->forceFill([
                'status' => AssetImportStatus::Review,
                'outcome' => null,
                'result' => null,
                'stop_requested_at' => null,
                'stopped_by' => null,
                'expires_at' => now()->addDays((int) config('assets.import.draft_days', 7)),
            ])->save();
            $import->increment('revision');

            $rows = $import->rows()->whereNull('outcome')->whereNotNull('previous_failure_code')->get();
            $this->validation->revalidateRows($import, $rows);
        });

        $this->audit->activity(
            ActivityAction::AssetImportRetried,
            actor: $actor,
            subject: $import,
            properties: ['import' => $import->uuid, 'rows' => $count],
            request: $request,
            module: 'assets',
            description: "Asset import retried for {$count} rows",
        );

        return $import->refresh();
    }
}
