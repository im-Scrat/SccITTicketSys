<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Controllers\Admin;

use App\Domains\FloorPlan\Http\Resources\RoomPlanResource;
use App\Domains\FloorPlan\Services\FloorPlanService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The floor-plan map (SRS FR-FP-002) — read-only. Thin controller: the service
 * authorizes, resolves and assembles; the resource shapes.
 *
 * The room arrives as a **string**, not a route-bound model, on purpose. Implicit
 * binding runs before the `can` middleware, so a bound `Room` would answer an
 * unauthorized caller with a 404 for an unknown uuid and a 403 for a real one —
 * an oracle for which rooms exist. Resolving inside the service, after
 * authorization, gives the same 403 either way.
 */
class FloorPlanController extends Controller
{
    public function showRoom(Request $request, string $room, FloorPlanService $plans): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return (new RoomPlanResource($plans->roomPlan($actor, $room)))->response();
    }
}
