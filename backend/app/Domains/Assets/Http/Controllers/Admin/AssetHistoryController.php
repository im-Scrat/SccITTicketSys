<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Services\AssetHistory;
use App\Domains\Identity\Http\Resources\ActivityLogResource;
use App\Domains\Maintenance\Http\Resources\MaintenanceDetailResource;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\MaintenanceRecord;
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

    /**
     * The real maintenance history (WP-G) — every visit against this machine,
     * in full: diagnosis, root cause, resolution, preventive recommendation,
     * downtime, labour, cost, the PC state a visit displaced, its linked
     * ticket, hardware replacements, checklist, evidence and notes.
     *
     * {@see timeline()} and the PC detail page's own Maintenance tab
     * (`MaintenanceSummaryResource`) are each a deliberately lossy projection
     * for their own surface — the unified asset timeline collapses a whole
     * visit into one generic entry (title, one of resolution-or-diagnosis,
     * status, downtime, cost), and the summary card drops the checklist,
     * evidence, notes, preventive recommendation, PC-state-before and
     * hardware replacements entirely. Neither is wrong for what it is; this
     * endpoint exists because the floor-plan PC inspector (WP-G) needs the
     * record itself, not a projection of it — so it reuses
     * `MaintenanceDetailResource`, the exact shape `MaintenanceDetailPage`
     * already renders, rather than inventing a third shape.
     *
     * Same authorization floor as the timeline and the audit trail above
     * (`viewHistory` — Administrator-only per DD-38's `assets.view`, and the
     * route itself repeats it): a PC unit absent from an actor's reach is
     * refused identically whether the uuid is real, archived or invented.
     */
    public function pcUnitMaintenance(Request $request, PcUnit $pcUnit): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $pcUnit);

        $records = MaintenanceRecord::query()
            ->where('pc_unit_id', $pcUnit->getKey())
            ->with([
                'type:id,name,slug,is_preventive',
                'technician:id,uuid,first_name,last_name',
                'createdBy:id,uuid,first_name,last_name',
                'ticket:id,uuid,ticket_number,title',
                'pcUnit:id,uuid,unit_code,pc_name,room_id,status,current_condition',
                'pcUnit.room:id,uuid,name,floor_id',
                'pcUnit.room.floor:id,uuid,name,floor_number,building_id',
                'pcUnit.room.floor.building:id,uuid,name,code',
                'checklists' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'checklists.completedBy:id,uuid,first_name,last_name',
                'images' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
                'images.uploadedBy:id,uuid,first_name,last_name',
                'notes' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
                'notes.technician:id,uuid,first_name,last_name',
                'hardwareReplacements' => fn ($q) => $q->orderByDesc('replaced_at')->orderByDesc('id'),
                'hardwareReplacements.oldComponent',
                'hardwareReplacements.newComponent',
                'hardwareReplacements.oldAsset:id,uuid,asset_tag,name,hardware_model_id',
                'hardwareReplacements.newAsset:id,uuid,asset_tag,name,hardware_model_id',
            ])
            ->withCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
                'images',
                'notes',
            ])
            ->orderByDesc('maintenance_date')
            ->orderByDesc('id')
            ->get();

        return MaintenanceDetailResource::collection($records);
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
