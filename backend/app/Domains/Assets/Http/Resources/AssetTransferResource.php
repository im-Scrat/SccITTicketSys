<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Models\AssetTransfer;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One room-to-room move (SRS FR-AST-006).
 *
 * Both endpoints are rendered as full "Building · Floor · Room" labels rather
 * than bare room names, because a transfer only makes sense in context — "Room 3
 * → Room 7" says nothing if they are in different buildings.
 *
 * @mixin AssetTransfer
 */
class AssetTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'from' => $this->location($this->fromRoom),
            'to' => $this->location($this->toRoom),
            'reason' => $this->reason,
            'remarks' => $this->remarks,
            'transferred_by' => $this->transferredBy?->fullName(),
            'transferred_at' => $this->transferred_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function location(?Room $room): ?array
    {
        if ($room === null) {
            return null;
        }

        $floor = $room->floor;
        $building = $floor?->building;

        $parts = array_values(array_filter([
            $building?->name,
            $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
            $room->name,
        ]));

        return [
            'id' => $room->uuid,
            'name' => $room->name,
            'label' => implode(' · ', $parts),
            'archived' => $room->deleted_at !== null,
        ];
    }
}
