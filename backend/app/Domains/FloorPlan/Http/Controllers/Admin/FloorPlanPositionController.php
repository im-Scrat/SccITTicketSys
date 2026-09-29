<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Controllers\Admin;

use App\Domains\FloorPlan\Actions\PlacePcUnit;
use App\Domains\FloorPlan\Http\Requests\PlacePcUnitRequest;
use App\Domains\FloorPlan\Http\Resources\FloorPlanPcResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Placing and moving PC units on a room's active layout (SRS FR-FP-003/009).
 *
 * Thin: the action authorizes, resolves, validates the point against the
 * layout and writes; the resource shapes the stored result.
 *
 * Like the map endpoint, every identifier arrives as a **string** and is
 * resolved after authorization — a bound model would answer an unauthorized
 * caller 404-or-403 depending on whether the uuid is real.
 */
class FloorPlanPositionController extends Controller
{
    public function update(
        PlacePcUnitRequest $request,
        string $room,
        string $version,
        string $pcUnit,
        PlacePcUnit $place,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $position = $place->handle(
            $actor,
            $room,
            (int) $version,
            $pcUnit,
            (float) $request->validated('x'),
            (float) $request->validated('y'),
            $request->has('snap') ? $request->boolean('snap') : null,
        );

        return (new FloorPlanPcResource($position))->response();
    }
}
