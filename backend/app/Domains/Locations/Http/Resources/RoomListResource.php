<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact room row for the directory table (SRS FR-LOC-003). Carries the parent
 * floor/building context so a room is readable out of the tree, plus the live
 * PC-unit count that drives the occupancy column.
 *
 * @mixin Room
 */
class RoomListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'room_number' => $this->room_number,
            'room_type' => $this->room_type->value,
            'room_type_label' => $this->room_type->label(),
            'capacity' => $this->capacity !== null ? (int) $this->capacity : null,
            'is_active' => $this->is_active,
            'pc_units_count' => (int) ($this->pc_units_count ?? 0),
            'floor' => $this->whenLoaded('floor', fn (): array => [
                'id' => $this->floor?->uuid,
                'floor_number' => $this->floor !== null ? (int) $this->floor->floor_number : null,
                'name' => $this->floor?->name,
                'archived' => $this->floor?->deleted_at !== null,
            ]),
            'building' => $this->when(
                $this->relationLoaded('floor') && $this->floor?->relationLoaded('building'),
                fn (): array => [
                    'id' => $this->floor?->building?->uuid,
                    'name' => $this->floor?->building?->name,
                    'code' => $this->floor?->building?->code,
                    'is_active' => $this->floor?->building?->is_active,
                    'archived' => $this->floor?->building?->deleted_at !== null,
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
