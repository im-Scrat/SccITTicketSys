<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Resources;

use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One branch of the location tree: a building with its floors and per-level
 * counts. Rooms are intentionally absent — the explorer fetches them per floor
 * on expand (`GET /admin/rooms?floor=<uuid>`) so the tree payload stays bounded
 * regardless of estate size (NFR-PERF).
 *
 * @mixin Building
 */
class LocationTreeResource extends JsonResource
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
            'is_active' => $this->is_active,
            'floors_count' => (int) ($this->floors_count ?? 0),
            'rooms_count' => (int) ($this->rooms_count ?? 0),
            'floors' => $this->floors
                ->map(fn (Floor $floor): array => [
                    'id' => $floor->uuid,
                    'floor_number' => (int) $floor->floor_number,
                    'name' => $floor->name,
                    'rooms_count' => (int) ($floor->rooms_count ?? 0),
                ])
                ->all(),
        ];
    }
}
