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
 * `updated_at` (WP-F) is the optimistic-concurrency token (D3): a client
 * echoes it back as `expected_updated_at` on its next move of this unit, and
 * `PlacePcUnit` refuses with 409 when it no longer matches what is stored. It
 * is a plain timestamp, not register data, so it carries no confidentiality
 * concern.
 *
 * @property FloorPlanPosition $resource
 */
class FloorPlanPcResource extends JsonResource
{
    /**
     * Microsecond precision, not the default second precision `toIso8601String()`
     * gives in the Carbon version installed here (it takes no arguments — this
     * project's `toIso8601String(true)` calls silently ignored the flag and
     * still truncated). Two moves of the same unit inside one wall-clock
     * second are a real and common case (D3's own two-client scenario, run
     * quickly), and a token that can't tell them apart stops being optimistic
     * concurrency and starts being a coin flip. `PlacePcUnit::assertNotStale()`
     * parses and re-formats an incoming token with this exact string, so both
     * sides of the comparison always agree on precision.
     */
    public const UPDATED_AT_FORMAT = 'Y-m-d\TH:i:s.uP';

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
            'updated_at' => $position->updated_at?->format(self::UPDATED_AT_FORMAT),
        ];
    }
}
