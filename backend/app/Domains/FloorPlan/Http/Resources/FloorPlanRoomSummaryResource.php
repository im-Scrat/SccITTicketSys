<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Resources;

use App\Enums\PcStatus;
use App\Models\Room;
use App\Models\RoomLayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card of the Floorplan room overview (Phase 2.11).
 *
 * Identifiers are uuids only; the "last saved by" is a display name, never a
 * user id or email. No serials, network details or anything else from the
 * asset record leaves through this resource — the card needs none of it.
 *
 * @property Room $resource
 */
class FloorPlanRoomSummaryResource extends JsonResource
{
    /**
     * @param  array<string, mixed>|null  $layout  the overview's per-room layout summary
     */
    public function __construct(Room $room, private readonly ?array $layout)
    {
        parent::__construct($room);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->resource;
        $total = (int) $room->getAttribute('pc_units_count');
        $pending = (int) $room->getAttribute('pending_repair_count');
        $broken = (int) $room->getAttribute('needs_fix_count');
        /** @var RoomLayout|null $layout */
        $layout = $this->layout['layout'] ?? null;

        return [
            'id' => $room->uuid,
            'name' => $room->name,
            'code' => $room->code,
            'room_type' => ['value' => $room->room_type->value, 'label' => $room->room_type->label()],
            'is_active' => $room->is_active,
            'capacity' => $room->capacity,
            'floor' => $room->floor === null ? null : [
                'name' => $room->floor->name,
                'floor_number' => $room->floor->floor_number,
            ],
            'building' => $room->floor?->building === null ? null : [
                'name' => $room->floor->building->name,
                'code' => $room->floor->building->code,
            ],
            'pcs' => [
                'total' => $total,
                'placed' => (int) ($this->layout['placed'] ?? 0),
                'by_status' => [
                    $this->status(PcStatus::Available, $total - $pending - $broken),
                    $this->status(PcStatus::PendingRepair, $pending),
                    $this->status(PcStatus::NeedsFix, $broken),
                ],
            ],
            'layout' => $layout === null ? null : [
                'version' => $layout->version,
                'revision' => $layout->revision,
                'width' => $layout->width,
                'height' => $layout->height,
                'updated_at' => $layout->updated_at?->toIso8601String(),
                'updated_by' => $layout->updatedBy === null ? null : ['name' => $layout->updatedBy->fullName()],
            ],
            'preview' => $this->layout['preview'] ?? null,
        ];
    }

    /**
     * @return array{value: string, label: string, tone: string, count: int}
     */
    private function status(PcStatus $status, int $count): array
    {
        return ['value' => $status->value, 'label' => $status->label(), 'tone' => $status->tone(), 'count' => max(0, $count)];
    }
}
