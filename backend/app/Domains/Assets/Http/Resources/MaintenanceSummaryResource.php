<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\MaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A maintenance visit as it appears on an asset or PC detail page
 * (SRS FR-MNT-003, FR-PC-006).
 *
 * Deliberately a **summary**: enough to read the service history at a glance —
 * what was done, by whom, how long the machine was down, what it cost — without
 * pulling in checklists, notes and repair images. The full Maintenance module
 * arrives in a later phase and will own the detail view; this resource is the
 * read-only window Phase 2.5 needs, and it works today because the records
 * already exist.
 *
 * @mixin MaintenanceRecord
 */
class MaintenanceSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'type' => $this->type?->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'diagnosis' => $this->diagnosis,
            'root_cause' => $this->root_cause,
            'resolution' => $this->resolution,
            'downtime_minutes' => $this->downtime_minutes,
            'labor_hours' => $this->labor_hours,
            'cost' => $this->cost,
            'technician' => $this->technician?->fullName(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'maintenance_date' => $this->maintenance_date?->toIso8601String(),
        ];
    }
}
