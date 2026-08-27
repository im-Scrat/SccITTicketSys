<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full room record for the detail page (SRS FR-LOC-003/005): attributes, the
 * parent floor/building chain, occupancy counts and the blame trail.
 *
 * `selectable` answers the picker question directly — a room is offered as
 * location context only when it and its whole parent chain are available
 * (FR-LOC-004/005). The blocker report is attached under `meta` by the
 * controller, not here.
 *
 * @mixin Room
 */
class RoomDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $floor = $this->floor;
        $building = $floor?->building;

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'room_number' => $this->room_number,
            'room_type' => $this->room_type->value,
            'room_type_label' => $this->room_type->label(),
            'capacity' => $this->capacity !== null ? (int) $this->capacity : null,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'pc_units_count' => (int) ($this->pc_units_count ?? 0),
            'assets_count' => (int) ($this->assets_count ?? 0),
            'consumables_count' => (int) ($this->consumables_count ?? 0),
            'tickets_count' => (int) ($this->tickets_count ?? 0),
            'floor' => $floor !== null ? [
                'id' => $floor->uuid,
                'floor_number' => (int) $floor->floor_number,
                'name' => $floor->name,
                'archived' => $floor->deleted_at !== null,
            ] : null,
            'building' => $building !== null ? [
                'id' => $building->uuid,
                'name' => $building->name,
                'code' => $building->code,
                'is_active' => $building->is_active,
                'archived' => $building->deleted_at !== null,
            ] : null,
            'selectable' => $this->is_active
                && $this->deleted_at === null
                && $floor !== null
                && $floor->deleted_at === null
                && $building !== null
                && $building->is_active
                && $building->deleted_at === null,
            'created_by' => $this->createdBy?->fullName(),
            'updated_by' => $this->updatedBy?->fullName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
            'archived_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
