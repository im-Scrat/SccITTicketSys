<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Controllers\Admin;

use App\Domains\Locations\Actions\ArchiveLocation;
use App\Domains\Locations\Actions\CreateRoom;
use App\Domains\Locations\Actions\ReassignRoomOccupants;
use App\Domains\Locations\Actions\RestoreLocation;
use App\Domains\Locations\Actions\SetLocationActive;
use App\Domains\Locations\Actions\UpdateRoom;
use App\Domains\Locations\Http\Requests\IndexRoomsRequest;
use App\Domains\Locations\Http\Requests\ReassignRoomRequest;
use App\Domains\Locations\Http\Requests\StoreRoomRequest;
use App\Domains\Locations\Http\Requests\UpdateRoomRequest;
use App\Domains\Locations\Http\Resources\RoomDetailResource;
use App\Domains\Locations\Http\Resources\RoomListResource;
use App\Domains\Locations\Services\LocationDirectoryQuery;
use App\Domains\Locations\Services\LocationGuard;
use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Room CRUD (SRS FR-LOC-003/004/005) plus the occupant-reassignment escape hatch
 * that lets an in-use room be retired. Thin controller: authorize → Action →
 * Resource; the directory query owns search/filter/sort/pagination.
 */
class RoomController extends Controller
{
    public function index(IndexRoomsRequest $request, LocationDirectoryQuery $directory): AnonymousResourceCollection
    {
        return RoomListResource::collection($directory->rooms($request->validated()));
    }

    public function show(Request $request, Room $room, LocationGuard $guard): JsonResponse
    {
        $this->authorize('view', $room);

        $room->load(['floor.building', 'createdBy', 'updatedBy'])
            ->loadCount(['pcUnits', 'assets', 'consumables', 'tickets']);

        $blockers = $guard->roomBlockers($room);

        return (new RoomDetailResource($room))
            ->additional(['meta' => [
                'blockers' => $blockers,
                'in_use' => array_sum($blockers) > 0,
            ]])
            ->response();
    }

    public function store(StoreRoomRequest $request, CreateRoom $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $floor = Floor::query()->where('uuid', $request->validated('floor'))->firstOrFail();

        $room = $action->handle($floor, $request->validated(), $actor, $request);

        return (new RoomDetailResource($room->loadCount(['pcUnits', 'assets', 'consumables', 'tickets'])))
            ->additional(['message' => 'Room created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRoomRequest $request, Room $room, UpdateRoom $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $targetFloorUuid = $request->validated('floor');
        $floor = is_string($targetFloorUuid) && $targetFloorUuid !== ''
            ? Floor::query()->where('uuid', $targetFloorUuid)->firstOrFail()
            : null;

        $action->handle($room, $request->validated(), $actor, $request, $floor);

        return (new RoomDetailResource(
            $room->load(['floor.building', 'createdBy', 'updatedBy'])
                ->loadCount(['pcUnits', 'assets', 'consumables', 'tickets'])
        ))
            ->additional(['message' => 'Room updated.'])
            ->response();
    }

    public function activate(Request $request, Room $room, SetLocationActive $action): JsonResponse
    {
        $this->authorize('activate', $room);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($room, true, $actor, $request);

        return (new RoomDetailResource($room->load('floor.building')))
            ->additional(['message' => 'Room activated.'])
            ->response();
    }

    public function deactivate(Request $request, Room $room, SetLocationActive $action): JsonResponse
    {
        $this->authorize('activate', $room);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($room, false, $actor, $request);

        return (new RoomDetailResource($room->load('floor.building')))
            ->additional(['message' => 'Room deactivated — it is no longer offered as a location.'])
            ->response();
    }

    public function destroy(Request $request, Room $room, ArchiveLocation $action): JsonResponse
    {
        $this->authorize('delete', $room);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($room, $actor, $request);

        return response()->json(['message' => 'Room archived.']);
    }

    public function restore(Request $request, Room $room, RestoreLocation $action): JsonResponse
    {
        $this->authorize('restore', $room);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($room, $actor, $request);

        return (new RoomDetailResource($room->refresh()->load('floor.building')))
            ->additional(['message' => 'Room restored.'])
            ->response();
    }

    /** Move this room's live PC units, assets and stock to another room. */
    public function reassign(ReassignRoomRequest $request, Room $room, ReassignRoomOccupants $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $target = Room::query()->where('uuid', $request->validated('to_room'))->firstOrFail();

        $moved = $action->handle($room, $target, $actor, $request);

        return response()->json([
            'message' => 'Occupants moved to '.$target->name.'.',
            'moved' => $moved,
        ]);
    }
}
