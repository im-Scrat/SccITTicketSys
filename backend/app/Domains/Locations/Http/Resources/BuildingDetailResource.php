<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Resources;

use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full building record for the detail page (SRS FR-LOC-001): attributes, counts,
 * blame trail, and the floor list when loaded.
 *
 * The in-use blocker report (FR-LOC-004) is *not* part of the record — the
 * controller attaches it under `meta` via `->additional()`, because it is
 * derived operational state rather than a building attribute.
 *
 * @mixin Building
 */
class BuildingDetailResource extends JsonResource
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
            'description' => $this->description,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'floors_count' => (int) ($this->floors_count ?? 0),
            'rooms_count' => (int) ($this->rooms_count ?? 0),
            'floors' => FloorResource::collection($this->whenLoaded('floors')),
            'created_by' => $this->createdBy?->fullName(),
            'updated_by' => $this->updatedBy?->fullName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
            'archived_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
