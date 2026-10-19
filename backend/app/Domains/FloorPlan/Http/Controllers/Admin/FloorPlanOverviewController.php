<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Controllers\Admin;

use App\Domains\FloorPlan\Http\Requests\IndexFloorPlanRoomsRequest;
use App\Domains\FloorPlan\Http\Resources\FloorPlanRoomSummaryResource;
use App\Domains\FloorPlan\Services\FloorPlanOverviewQuery;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The Floorplan landing page's room overview (Phase 2.11). Thin: the query
 * authorizes and gathers, the resource shapes one card.
 */
class FloorPlanOverviewController extends Controller
{
    public function index(IndexFloorPlanRoomsRequest $request, FloorPlanOverviewQuery $overview): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $overview->handle($actor, $request->validated());
        $rooms = $result['rooms'];

        return response()->json([
            'data' => collect($rooms->items())
                ->map(fn (Room $room): array => (new FloorPlanRoomSummaryResource($room, $result['layouts'][$room->getKey()] ?? null))->resolve($request))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $rooms->currentPage(),
                'last_page' => $rooms->lastPage(),
                'per_page' => $rooms->perPage(),
                'total' => $rooms->total(),
                // PCs with no room — the grey "Unassigned" category (DD-100);
                // the overview links to them rather than listing them.
                'unassigned_pcs' => $result['unassigned'],
            ],
        ]);
    }
}
