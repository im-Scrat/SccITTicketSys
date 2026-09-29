<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Controllers\Admin;

use App\Domains\FloorPlan\Actions\ActivateRoomLayout;
use App\Domains\FloorPlan\Actions\CreateRoomLayout;
use App\Domains\FloorPlan\Http\Requests\StoreRoomLayoutRequest;
use App\Domains\FloorPlan\Http\Resources\RoomLayoutResource;
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
        );

        return (new RoomLayoutResource($layout))->response()->setStatusCode(201);
    }

    public function activate(Request $request, string $room, string $version, ActivateRoomLayout $activate): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $layout = $activate->handle($actor, $room, (int) $version, $request);

        return (new RoomLayoutResource($layout))->response();
    }
}
