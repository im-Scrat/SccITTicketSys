<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Resources;

use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact building row for the directory table (SRS FR-LOC-001). Uuid-only;
 * `archived` reflects the soft-delete state so the table can badge archived rows.
 *
 * @mixin Building
 */
class BuildingListResource extends JsonResource
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
            'address' => $this->address,
            'is_active' => $this->is_active,
            'floors_count' => (int) ($this->floors_count ?? 0),
            'rooms_count' => (int) ($this->rooms_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
