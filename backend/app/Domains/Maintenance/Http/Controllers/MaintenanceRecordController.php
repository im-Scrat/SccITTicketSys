<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Identity\Http\Resources\ActivityLogResource;
use App\Domains\Maintenance\Actions\CreateMaintenanceRecord;
use App\Domains\Maintenance\Actions\UpdateMaintenanceRecord;
use App\Domains\Maintenance\Http\Requests\ChangeMaintenanceStatusRequest;
use App\Domains\Maintenance\Http\Requests\StoreMaintenanceRecordRequest;
use App\Domains\Maintenance\Http\Requests\UpdateMaintenanceRecordRequest;
use App\Domains\Maintenance\Http\Resources\MaintenanceDetailResource;
use App\Domains\Maintenance\Services\MaintenanceLifecycle;
use App\Enums\MaintenanceStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * One maintenance record — the surface a technician works the job on
 * (SRS FR-MNT-001..006, FR-MNT-011).
 *
 * The route gate is `maintenance.view`/`maintenance.create`, which is a floor
 * rather than a fence: Administrators and Technicians both hold the whole
 * module. `MaintenanceRecordPolicy` is what decides, and it asks
 * `MaintenanceVisibility` — the same service the list queries use — so a record
 * absent from someone's queue is equally unreachable by pasting its uuid.
 *
 * **No method here writes a status column.** Every lifecycle move goes through
 * `MaintenanceLifecycle`, which is the only place that knows the transition map,
 * the completion gates and the PC-unit effect, and the only place that writes
 * the audit row for them.
 */
class MaintenanceRecordController extends Controller
{
    public function __construct(
        private readonly CreateMaintenanceRecord $creator,
        private readonly UpdateMaintenanceRecord $updater,
        private readonly MaintenanceLifecycle $lifecycle,
    ) {}

    /** The full record, with its checklist, evidence, notes and replacements. */
    public function show(Request $request, MaintenanceRecord $record): MaintenanceDetailResource
    {
        // Refuses any record this caller holds no claim on — the same check the
        // queue query applies, so a uuid is not a way around it.
        $this->authorize('view', $record);

        return new MaintenanceDetailResource($this->loadDetail($record));
    }

    /**
     * The record's own audit timeline (SRS FR-AUD-003).
     *
     * Reuses the Identity `ActivityLogResource`: the audit vocabulary is shared
     * platform-wide, and it moves to a common namespace with the Administration
     * audit viewers in WP-2.9.
     *
     * Scoped by `viewAudit`, not by `viewAdministrative`: a technician is
     * accountable for their own record and must be able to read what was done
     * to it — but only to it.
     */
    public function audit(Request $request, MaintenanceRecord $record): AnonymousResourceCollection
    {
        $this->authorize('viewAudit', $record);

        $logs = ActivityLog::query()
            ->with('user:id,uuid,first_name,last_name')
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ActivityLogResource::collection($logs);
    }

    /**
     * Open a record (SRS FR-MNT-001/002).
     *
     * Answers 201 with the record **and** a `concurrent` list naming any open
     * maintenance already targeting the same machine. That warning is
     * deliberately non-blocking (Client decision, 2026-08-28): a machine may
     * legitimately carry a scheduled preventive visit and an active corrective
     * repair at the same time, so the useful thing is to tell the technician —
     * not to refuse the record and make whoever is right work around the system.
     */
    public function store(StoreMaintenanceRecordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->creator->handle($request->validated(), $user, $request);

        return (new MaintenanceDetailResource($this->loadDetail($result['record'])))
            ->additional(['meta' => ['concurrent' => $result['concurrent']]])
            ->response()
            ->setStatusCode(201);
    }

    /** Edit an open record's detail (SRS FR-MNT-003). */
    public function update(UpdateMaintenanceRecordRequest $request, MaintenanceRecord $record): MaintenanceDetailResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MaintenanceDetailResource(
            $this->loadDetail($this->updater->handle($record, $request->validated(), $user, $request)),
        );
    }

    /**
     * Move the record through its lifecycle (SRS FR-MNT-003/004/008/010).
     *
     * The route gate and the FormRequest only ask "may you attempt a
     * transition". `MaintenanceLifecycle` decides which moves are legal from
     * here and whether the completion gates are met, answering 422 with either
     * the reachable set or the specific blockers.
     */
    public function changeStatus(ChangeMaintenanceStatusRequest $request, MaintenanceRecord $record): MaintenanceDetailResource
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validated();

        $updated = $this->lifecycle->transition(
            $record,
            MaintenanceStatus::from($data['status']),
            $user,
            $data,
            $request,
        );

        return new MaintenanceDetailResource($this->loadDetail($updated));
    }

    /**
     * Hand the record to a different technician (SRS FR-MNT-011).
     *
     * Administrator-only, and its own endpoint rather than a field on the edit
     * form: distributing work is oversight, and folding it into `update()`
     * would put an administrator-only capability behind a technician-writable
     * request.
     */
    public function reassign(Request $request, MaintenanceRecord $record): MaintenanceDetailResource
    {
        $this->authorize('reassign', $record);

        $validated = $request->validate([
            'technician' => ['required', 'uuid', 'exists:users,uuid'],
        ]);

        $technician = User::query()->where('uuid', $validated['technician'])->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();

        return new MaintenanceDetailResource(
            $this->loadDetail($this->updater->reassign($record, $technician, $actor, $request)),
        );
    }

    /**
     * Archive (soft delete).
     *
     * A Technician holds `maintenance.delete` in the seeded baseline, so the
     * policy — not the permission — caps them at their own `scheduled` or
     * `cancelled` records. Anything describing work that actually happened is
     * audit material and stays.
     */
    public function destroy(Request $request, MaintenanceRecord $record): JsonResponse
    {
        $this->authorize('delete', $record);

        /** @var User $user */
        $user = $request->user();

        $this->updater->archive($record, $user, $request);

        return response()->json(['message' => 'Maintenance record archived.']);
    }

    /** Restore an archived record — administrators only. */
    public function restore(Request $request, MaintenanceRecord $record): MaintenanceDetailResource
    {
        $this->authorize('restore', $record);

        /** @var User $user */
        $user = $request->user();

        return new MaintenanceDetailResource(
            $this->loadDetail($this->updater->restore($record, $user, $request)),
        );
    }

    /**
     * Eager-load everything the detail view renders.
     *
     * Checklist items come back in **issue** order rather than insertion order,
     * so the list reads the way it was written even after the template behind
     * it was reordered.
     */
    private function loadDetail(MaintenanceRecord $record): MaintenanceRecord
    {
        return $record->load([
            'type:id,name,slug,is_preventive',
            'technician:id,uuid,first_name,last_name',
            'createdBy:id,uuid,first_name,last_name',
            'ticket:id,uuid,ticket_number,title',
            'pcUnit:id,uuid,unit_code,pc_name,room_id,status,current_condition',
            'pcUnit.room:id,uuid,name,floor_id',
            'pcUnit.room.floor:id,uuid,name,floor_number,building_id',
            'pcUnit.room.floor.building:id,uuid,name,code',
            'asset:id,uuid,asset_tag,name,current_room_id,status,hardware_model_id',
            'asset.currentRoom:id,uuid,name,floor_id',
            'asset.currentRoom.floor:id,uuid,name,floor_number,building_id',
            'asset.currentRoom.floor.building:id,uuid,name,code',
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
        ])->loadCount([
            'checklists',
            'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            'images',
            'notes',
        ]);
    }
}
