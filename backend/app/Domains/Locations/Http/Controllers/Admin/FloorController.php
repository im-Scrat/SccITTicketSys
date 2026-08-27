<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Controllers\Admin;

use App\Domains\Locations\Actions\ArchiveLocation;
use App\Domains\Locations\Actions\CreateFloor;
use App\Domains\Locations\Actions\RestoreLocation;
use App\Domains\Locations\Actions\UpdateFloor;
use App\Domains\Locations\Http\Requests\StoreFloorRequest;
use App\Domains\Locations\Http\Requests\UpdateFloorRequest;
use App\Domains\Locations\Http\Resources\FloorResource;
use App\Domains\Locations\Services\LocationDirectoryQuery;
use App\Domains\Locations\Services\LocationGuard;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Floor management within a building (SRS FR-LOC-002/004). Floors are created
 * nested under their building and thereafter addressed by their own `uuid`.
 * The set per building is small and bounded, so the list is not paginated.
 */
class FloorController extends Controller
{
    public function index(Request $request, Building $building, LocationDirectoryQuery $directory): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Floor::class);

        $trashed = (string) $request->query('trashed', 'without');
        $trashed = in_array($trashed, ['without', 'with', 'only'], true) ? $trashed : 'without';

        return FloorResource::collection($directory->floorsOfBuilding($building, $trashed));
    }

    public function show(Request $request, Floor $floor, LocationGuard $guard): JsonResponse
    {
        $this->authorize('view', $floor);

        $floor->loadCount('rooms')->load(['building', 'createdBy', 'updatedBy']);

        $blockers = $guard->floorBlockers($floor);

        return (new FloorResource($floor))
            ->additional(['meta' => [
                'blockers' => $blockers,
                'in_use' => array_sum($blockers) > 0,
            ]])
            ->response();
    }

    public function store(StoreFloorRequest $request, Building $building, CreateFloor $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $floor = $action->handle($building, $request->validated(), $actor, $request);

        return (new FloorResource($floor->loadCount('rooms')))
            ->additional(['message' => 'Floor created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateFloorRequest $request, Floor $floor, UpdateFloor $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($floor, $request->validated(), $actor, $request);

        return (new FloorResource($floor->loadCount('rooms')->load('building')))
            ->additional(['message' => 'Floor updated.'])
            ->response();
    }

    public function destroy(Request $request, Floor $floor, ArchiveLocation $action): JsonResponse
    {
        $this->authorize('delete', $floor);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($floor, $actor, $request);

        return response()->json(['message' => 'Floor archived with its rooms.']);
    }

    public function restore(Request $request, Floor $floor, RestoreLocation $action): JsonResponse
    {
        $this->authorize('restore', $floor);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($floor, $actor, $request);

        return (new FloorResource($floor->refresh()->loadCount('rooms')->load('building')))
            ->additional(['message' => 'Floor restored.'])
            ->response();
    }
}
