<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Resources;

use App\Domains\FloorPlan\DTOs\RoomPlan;
use App\Models\FloorPlanPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The floor-plan map payload for one room.
 *
 * **Deliberately narrow.** Only what the drawing and its text alternative need:
 * an identifying label, a status (value, label and tone — the tone is the
 * server's decision, see `PcStatus::tone()`), and coordinates. No serial
 * number, network address, purchase data or notes: this is a picture of where
 * machines are, not the equipment register. Identifiers are uuids; no numeric
 * id appears anywhere (NFR-SEC-001).
 *
 * @property RoomPlan $resource
 */
class RoomPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        $layout = $plan->layout;

        return [
            'room' => [
                'id' => $plan->room->uuid,
                'name' => $plan->room->name,
                'code' => $plan->room->code,
                'floor' => $plan->room->floor === null ? null : [
                    'name' => $plan->room->floor->name,
                    'floor_number' => $plan->room->floor->floor_number,
                ],
                'building' => $plan->room->floor?->building === null ? null : [
                    'name' => $plan->room->floor->building->name,
                    'code' => $plan->room->floor->building->code,
                ],
            ],
            'layout' => $layout === null ? null : [
                'version' => $layout->version,
                'width' => $layout->width,
                'height' => $layout->height,
                'grid_size' => $layout->grid_size,
            ],
            'pcs' => $plan->positions
                ->map(fn (FloorPlanPosition $position): array => [
                    'id' => $position->pcUnit->uuid,
                    'name' => $position->pcUnit->pc_name,
                    'unit_code' => $position->pcUnit->unit_code,
                    'status' => [
                        'value' => $position->pcUnit->status->value,
                        'label' => $position->pcUnit->status->label(),
                        'tone' => $position->pcUnit->status->tone(),
                    ],
                    'x' => (float) $position->pos_x,
                    'y' => (float) $position->pos_y,
                    'rotation' => (float) $position->rotation,
                    'z_index' => $position->z_index,
                ])
                ->values()
                ->all(),
            'unplaced_count' => $plan->unplacedCount,
        ];
    }
}
