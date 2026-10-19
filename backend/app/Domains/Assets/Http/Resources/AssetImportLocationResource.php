<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Domains\Assets\Services\Import\ImportLocationMatcher;
use App\Models\AssetImportLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One location text and its decision (Phase 2.10 R6). Candidates carry their
 * reason and whether the room is in use; none is ever pre-applied unless it
 * matched exactly.
 *
 * @mixin AssetImportLocation
 */
class AssetImportLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->room;

        return [
            'id' => $this->uuid,
            'source_text' => $this->source_text,
            'is_blank' => $this->normalized === '',
            'parts' => $this->parts,
            'match' => $this->match->value,
            'match_label' => $this->match->label(),
            'candidates' => $this->candidates ?? [],
            'resolution' => $this->resolution->value,
            'resolution_label' => $this->resolution->label(),
            'room' => $room === null ? null : [
                'id' => $room->uuid,
                'label' => ImportLocationMatcher::roomLabel($room),
                'selectable' => $room->is_active && (bool) $room->floor?->building?->is_active,
                'archived' => $room->trashed(),
            ],
            'assign_anyway' => $this->assign_anyway,
            'row_count' => $this->row_count,
            'decided' => $this->decided_by !== null,
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
