<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Resources;

use App\Domains\Maintenance\Services\MaintenanceLifecycle;
use App\Models\MaintenanceRecord;
use Illuminate\Http\Request;

/**
 * The full maintenance record — the work surface a technician does the job on,
 * and the record an Administrator reviews (SRS FR-MNT-003/004/005/006).
 *
 * Extends {@see MaintenanceListResource} rather than restating it, so a field
 * added to the row cannot go missing from the detail view. What it adds is
 * everything a table has no room for: the narrative fields, the checklist, the
 * evidence, the notes, the replacements, and the set of transitions this caller
 * may actually perform.
 *
 * `available_transitions` is computed per-caller from
 * {@see MaintenanceLifecycle}, not hard-coded in the client. A button the API
 * would refuse is worse than no button, and the transition map is the only
 * thing that knows which moves an actor is entitled to.
 *
 * @mixin MaintenanceRecord
 */
class MaintenanceDetailResource extends MaintenanceListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::toArray($request),

            'diagnosis' => $this->diagnosis,
            'root_cause' => $this->root_cause,
            'resolution' => $this->resolution,
            'preventive_recommendation' => $this->preventive_recommendation,

            // What this visit displaced on the machine, so the detail view can
            // say "will be restored to Online" rather than leaving the restore
            // a surprise (FR-MNT-008).
            'pc_state_before' => $this->pc_status_before !== null ? [
                'status' => $this->pc_status_before->value,
                'status_label' => $this->pc_status_before->label(),
                'condition' => $this->pc_condition_before?->value,
                'condition_label' => $this->pc_condition_before?->label(),
            ] : null,

            'checklist_items' => MaintenanceChecklistResource::collection(
                $this->whenLoaded('checklists'),
            ),
            'evidence' => RepairImageResource::collection(
                $this->whenLoaded('images'),
            ),
            'notes' => MaintenanceNoteResource::collection(
                $this->whenLoaded('notes'),
            ),
            'hardware_replacements' => HardwareReplacementResource::collection(
                $this->whenLoaded('hardwareReplacements'),
            ),

            'available_transitions' => $user !== null
                ? app(MaintenanceLifecycle::class)->availableTransitions($this->resource, $user)
                : [],

            'abilities' => $user !== null ? [
                'update' => $user->can('update', $this->resource),
                'complete' => $user->can('complete', $this->resource),
                'reassign' => $user->can('reassign', $this->resource),
                'manage_evidence' => $user->can('manageEvidence', $this->resource),
                'record_replacement' => $user->can('recordReplacement', $this->resource),
                'archive' => $user->can('delete', $this->resource),
            ] : [],
        ];
    }
}
