<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Resources;

use App\Domains\FloorPlan\DTOs\RoomPlan;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
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
                ->map(fn (FloorPlanPosition $position): array => (new FloorPlanPcResource($position))->toArray($request))
                ->values()
                ->all(),
            // Units in the room with no place on this layout yet: the same
            // narrow identity-and-status shape as a placed unit, minus the
            // coordinates they do not have.
            'unplaced' => $plan->unplaced
                ->map(fn (PcUnit $pcUnit): array => [
                    'id' => $pcUnit->uuid,
                    'name' => $pcUnit->pc_name,
                    'unit_code' => $pcUnit->unit_code,
                    'status' => [
                        'value' => $pcUnit->status->value,
                        'label' => $pcUnit->status->label(),
                        'tone' => $pcUnit->status->tone(),
                    ],
                ])
                ->values()
                ->all(),
            'unplaced_count' => $plan->unplacedCount,
            // What the client may offer. A courtesy, not a control: every
            // write is authorized again by the route gate and `PlacePcUnit`.
            'editor' => [
                'can_edit' => $plan->canEdit,
                'snap_to_grid' => $plan->snapToGrid,
            ],
        ];
    }
}
