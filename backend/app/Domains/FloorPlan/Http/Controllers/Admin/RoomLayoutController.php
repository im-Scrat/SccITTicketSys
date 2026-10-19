<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Controllers\Admin;

use App\Domains\FloorPlan\Actions\ActivateRoomLayout;
use App\Domains\FloorPlan\Actions\CreateRoomLayout;
use App\Domains\FloorPlan\Actions\SaveRoomLayout;
use App\Domains\FloorPlan\Http\Requests\SaveRoomLayoutRequest;
use App\Domains\FloorPlan\Http\Requests\StoreRoomLayoutRequest;
use App\Domains\FloorPlan\Http\Resources\RoomLayoutResource;
use App\Domains\FloorPlan\Http\Resources\RoomPlanResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Layout persistence: new versions and switching which one is active
 * (SRS FR-FP-001; WP-F).
 *
 * Thin, like every other floor-plan controller: the actions authorize,
 * resolve and write; the resource shapes. `{room}` arrives as a string and is
 * resolved after authorization, for the same existence-oracle reason every
 * other floor-plan route does this.
 */
class RoomLayoutController extends Controller
{
    public function store(StoreRoomLayoutRequest $request, string $room, CreateRoomLayout $create): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $layout = $create->handle(
            $actor,
            $room,
            (int) $request->validated('width'),
            (int) $request->validated('height'),
            $request->validated('grid_size'),
            $request->validated('background_image'),
            $request,
            $request->boolean('activate'),
        );

        return (new RoomLayoutResource($layout))->response()->setStatusCode(201);
    }

    /**
     * Save the whole layout (Phase 2.11, SDD DD-101). Answers with the saved
     * plan in the same shape as the GET, so the editor resets its draft to
     * exactly what the server stored.
     */
    public function update(SaveRoomLayoutRequest $request, string $room, string $version, SaveRoomLayout $save): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{expected_revision: int, settings: array{width: int, height: int, grid_size: int}, workstations: list<array{pc_unit: string, x: float, y: float, rotation: int}>, fixtures: list<array{id: string, kind: string, x: float, y: float, width: float, height: float, rotation: float, label?: string|null}>} $data */
        $data = $request->validated();

        $plan = $save->handle(
            $actor,
            $room,
            (int) $version,
            (int) $data['expected_revision'],
            $data['settings'],
            $data['workstations'],
            $data['fixtures'],
            $request,
        );

        return (new RoomPlanResource($plan))->response();
    }

    public function activate(Request $request, string $room, string $version, ActivateRoomLayout $activate): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $layout = $activate->handle($actor, $room, (int) $version, $request);

        return (new RoomLayoutResource($layout))->response();
    }
}
