<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Resources;

use App\Models\MaintenanceChecklist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One instantiated checklist item on a maintenance record (SRS FR-MNT-004).
 *
 * `is_required` is read from the **instance**, never from the template item it
 * came from: the template is editable and deletable, and a completion gate that
 * evaporates when an administrator tidies the catalogue is not a gate.
 *
 * @mixin MaintenanceChecklist
 */
class MaintenanceChecklistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->item_label,
            'is_required' => (bool) $this->is_required,
            'is_completed' => (bool) $this->is_completed,
            'sort_order' => (int) $this->sort_order,
            'remarks' => $this->remarks,
            'completed_by' => $this->completedBy !== null ? [
                'id' => $this->completedBy->uuid,
                'name' => $this->completedBy->fullName(),
            ] : null,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
