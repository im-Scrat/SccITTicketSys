<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Resources;

use App\Models\FloorPlanPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PC unit as the map draws it: identity, status, and where it stands.
 *
 * The single shape for a placed machine — the room payload's `pcs` entries and
 * the answer to a placement are both this, so what a client reconciles to after
 * a move is exactly what it would have read from the map.
 *
 * Deliberately narrow (see `RoomPlanResource`): no serial number, network
 * address or other register data, and no numeric id. The status label and tone
 * are the server's (`PcStatus::label()/tone()`).
 *
 * @property FloorPlanPosition $resource
 */
class FloorPlanPcResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $position = $this->resource;
        $pcUnit = $position->pcUnit;

        return [
            'id' => $pcUnit->uuid,
            'name' => $pcUnit->pc_name,
            'unit_code' => $pcUnit->unit_code,
            'status' => [
                'value' => $pcUnit->status->value,
                'label' => $pcUnit->status->label(),
                'tone' => $pcUnit->status->tone(),
            ],
            'x' => (float) $position->pos_x,
            'y' => (float) $position->pos_y,
            'rotation' => (float) $position->rotation,
            'z_index' => $position->z_index,
        ];
    }
}
