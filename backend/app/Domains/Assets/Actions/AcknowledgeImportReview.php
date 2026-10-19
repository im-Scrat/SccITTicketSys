<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "I've reviewed" for a finished import (Phase 2.10 R7, SDD DD-99).
 *
 * Writes the three receipt columns and nothing else: a conditional
 * query-builder update — no model events, no `updated_at`, no audit noise —
 * that is a no-op when a receipt already covers every created record. A retry
 * that adds records re-arms it, because the receipt stores how many records it
 * covered. Closing the review without acknowledging writes nothing at all.
 */
final class AcknowledgeImportReview
{
    public function handle(AssetImport $import, User $actor): AssetImport
    {
        $created = DB::table('asset_import_rows')
            ->where('asset_import_id', $import->getKey())
            ->where('outcome', AssetImportRowOutcome::Created->value)
            ->count();

        if ($created > 0 && ! in_array($import->status, [AssetImportStatus::Queued, AssetImportStatus::Importing], true)) {
            DB::table('asset_imports')
                ->where('id', $import->getKey())
                ->where(fn ($q) => $q->whereNull('reviewed_created_count')->orWhere('reviewed_created_count', '<', $created))
                ->update([
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->getKey(),
                    'reviewed_created_count' => $created,
                ]);
        }

        return $import->refresh();
    }
}
