<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Resources;

use App\Models\RoomLayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A room layout, addressed the way WP-B established: no uuid of its own,
 * only its `version` inside a room already named by uuid elsewhere in the
 * response or the route (SRS FR-FP-001).
 *
 * @property RoomLayout $resource
 */
class RoomLayoutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $layout = $this->resource;

        return [
            'version' => $layout->version,
            'width' => $layout->width,
            'height' => $layout->height,
            'grid_size' => $layout->grid_size,
            'is_active' => $layout->is_active,
        ];
    }
}
