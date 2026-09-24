<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Resources;

use App\Models\MaintenanceNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A free-form working note on a maintenance record (SRS FR-MNT-005).
 *
 * Notes are staff-only by construction rather than by a flag: no non-staff role
 * can reach a maintenance record at all, so unlike `ticket_comments` there is no
 * `is_internal` distinction to draw here.
 *
 * @mixin MaintenanceNote
 */
class MaintenanceNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => $this->technician !== null ? [
                'id' => $this->technician->uuid,
                'name' => $this->technician->fullName(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
