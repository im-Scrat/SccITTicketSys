<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Resources;

use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A floor within a building (SRS FR-LOC-002). Addressed by the `uuid` added in
 * Phase 2.4 — the numeric key is never exposed. The parent building is included
 * when the relation is loaded, so a floor row is self-describing in the UI.
 *
 * @mixin Floor
 */
class FloorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'floor_number' => (int) $this->floor_number,
            'name' => $this->name,
            'description' => $this->description,
            'rooms_count' => (int) ($this->rooms_count ?? 0),
            'building' => $this->whenLoaded('building', fn (): array => [
                'id' => $this->building?->uuid,
                'name' => $this->building?->name,
                'code' => $this->building?->code,
                'is_active' => $this->building?->is_active,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
            'archived_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
