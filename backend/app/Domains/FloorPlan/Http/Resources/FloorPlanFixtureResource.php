<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Resources;

use App\Models\FloorPlanFixture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fixture as the editor holds it (Phase 2.11, SDD DD-102): the same shape
 * the save endpoint accepts, so a loaded plan can be sent straight back.
 *
 * @property FloorPlanFixture $resource
 */
class FloorPlanFixtureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fixture = $this->resource;

        return [
            'id' => $fixture->uuid,
            'kind' => $fixture->kind->value,
            'x' => (float) $fixture->pos_x,
            'y' => (float) $fixture->pos_y,
            'width' => (float) $fixture->width,
            'height' => (float) $fixture->height,
            'rotation' => (float) $fixture->rotation,
            'label' => $fixture->label,
        ];
    }
}
