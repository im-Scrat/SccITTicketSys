<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A selectable room in the narrow location lookup (FR-LOC-005/011).
 *
 * This is the *whole* location surface a non-administrator sees, so it is
 * deliberately minimal: **labels only** — no counts, no capacity, no custodians,
 * no blame columns, no timestamps, no archived rows. Nothing here reveals how the
 * estate is administered.
 *
 * `label` is the pre-composed "Building · Floor · Room" string the picker
 * renders, so the client never has to reassemble the hierarchy.
 *
 * @mixin Room
 */
class LocationOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $floor = $this->floor;
        $building = $floor?->building;

        $parts = array_values(array_filter([
            $building?->name,
            $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
            $this->name,
        ]));

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'room_type' => $this->room_type->value,
            'room_type_label' => $this->room_type->label(),
            'floor' => $floor !== null ? [
                'id' => $floor->uuid,
                'floor_number' => (int) $floor->floor_number,
                'name' => $floor->name,
            ] : null,
            'building' => $building !== null ? [
                'id' => $building->uuid,
                'name' => $building->name,
            ] : null,
            'label' => implode(' · ', $parts),
        ];
    }
}
