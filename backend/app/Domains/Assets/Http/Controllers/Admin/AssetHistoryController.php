<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Services\AssetHistory;
use App\Domains\Identity\Http\Resources\ActivityLogResource;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Two different readings of an asset's past, deliberately kept apart.
 *
 * {@see timeline()} is the **unified asset history** (SRS FR-AST-005): status
 * changes, transfers, installations, maintenance, tickets and QR events merged
 * into one chronological stream. It answers "what happened to this machine?".
 *
 * {@see audit()} is the **audit trail** (FR-AUD-003/004): the `activity_logs`
 * rows recorded against this record, newest first, with actor and IP. It answers
 * "who did what, from where?". It reuses the Identity `ActivityLogResource`
 * because the audit vocabulary is shared platform-wide (SDD §16), exactly as the
 * Locations audit viewer does.
 *
 * Collapsing them would serve neither question well: the timeline would be
 * cluttered with request metadata, and the audit trail would be diluted by
 * events that no actor performed.
 */
class AssetHistoryController extends Controller
{
    public function __construct(private readonly AssetHistory $history) {}

    /** The merged, newest-first asset timeline. */
    public function assetTimeline(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('viewHistory', $asset);

        $page = $this->history->forAsset(
            $asset,
            max(1, (int) $request->integer('page', 1)),
            (int) $request->integer('per_page', AssetHistory::DEFAULT_PER_PAGE),
        );

        return response()->json($page->toArray());
    }

    /** The merged, newest-first PC timeline. */
    public function pcUnitTimeline(Request $request, PcUnit $pcUnit): JsonResponse
    {
        $this->authorize('viewHistory', $pcUnit);

        $page = $this->history->forPcUnit(
            $pcUnit,
            max(1, (int) $request->integer('page', 1)),
            (int) $request->integer('per_page', AssetHistory::DEFAULT_PER_PAGE),
        );

        return response()->json($page->toArray());
    }

    public function assetAudit(Request $request, Asset $asset): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $asset);

        return $this->auditTrail($asset);
    }

    public function pcUnitAudit(Request $request, PcUnit $pcUnit): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $pcUnit);

        return $this->auditTrail($pcUnit);
    }

    private function auditTrail(Model $subject): AnonymousResourceCollection
    {
        $logs = ActivityLog::query()
            ->with('user:id,uuid,first_name,last_name')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ActivityLogResource::collection($logs);
    }
}
