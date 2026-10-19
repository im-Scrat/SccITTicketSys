<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Controllers\Admin;

use App\Domains\Locations\Actions\ArchiveLocation;
use App\Domains\Locations\Actions\CreateBuilding;
use App\Domains\Locations\Actions\RestoreLocation;
use App\Domains\Locations\Actions\SetLocationActive;
use App\Domains\Locations\Actions\UpdateBuilding;
use App\Domains\Locations\Http\Requests\IndexBuildingsRequest;
use App\Domains\Locations\Http\Requests\StoreBuildingRequest;
use App\Domains\Locations\Http\Requests\UpdateBuildingRequest;
use App\Domains\Locations\Http\Resources\BuildingDetailResource;
use App\Domains\Locations\Http\Resources\BuildingListResource;
use App\Domains\Locations\Services\LocationDirectoryQuery;
use App\Domains\Locations\Services\LocationGuard;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Building CRUD (SRS FR-LOC-001/004). Thin controller: it authorizes, delegates
 * the write to a single-purpose Action, and returns a Resource. Search / filter /
 * sort / pagination live in LocationDirectoryQuery; deletion is archive (soft
 * delete) + restore, never a hard delete (BR-12).
 */
class BuildingController extends Controller
{
    public function index(IndexBuildingsRequest $request, LocationDirectoryQuery $directory): AnonymousResourceCollection
    {
        return BuildingListResource::collection($directory->buildings($request->validated()));
    }

    public function show(Request $request, Building $building, LocationGuard $guard): JsonResponse
    {
        $this->authorize('view', $building);

        $building->loadCount(['floors', 'rooms'])
            ->load(['createdBy', 'updatedBy', 'floors' => fn ($query) => $query->withCount('rooms')->orderBy('floor_number')]);

        $blockers = $guard->buildingBlockers($building);

        return (new BuildingDetailResource($building))
            ->additional(['meta' => [
                'blockers' => $blockers,
                'in_use' => array_sum($blockers) > 0,
            ]])
            ->response();
    }

    public function store(StoreBuildingRequest $request, CreateBuilding $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $building = $action->handle($request->validated(), $actor, $request);

        return (new BuildingDetailResource($building->loadCount(['floors', 'rooms'])))
            ->additional(['message' => 'Building created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateBuildingRequest $request, Building $building, UpdateBuilding $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($building, $request->validated(), $actor, $request);

        return (new BuildingDetailResource($building->loadCount(['floors', 'rooms'])->load(['createdBy', 'updatedBy'])))
            ->additional(['message' => 'Building updated.'])
            ->response();
    }

    public function activate(Request $request, Building $building, SetLocationActive $action): JsonResponse
    {
        $this->authorize('activate', $building);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($building, true, $actor, $request);

        return (new BuildingDetailResource($building->loadCount(['floors', 'rooms'])))
            ->additional(['message' => 'Building activated.'])
            ->response();
    }

    public function deactivate(Request $request, Building $building, SetLocationActive $action): JsonResponse
    {
        $this->authorize('activate', $building);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($building, false, $actor, $request);

        return (new BuildingDetailResource($building->loadCount(['floors', 'rooms'])))
            ->additional(['message' => 'Building deactivated — its floors and rooms are no longer offered as locations.'])
            ->response();
    }

    public function destroy(Request $request, Building $building, ArchiveLocation $action): JsonResponse
    {
        $this->authorize('delete', $building);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($building, $actor, $request);

        return response()->json(['message' => 'Building archived with its floors and rooms.']);
    }

    public function restore(Request $request, Building $building, RestoreLocation $action): JsonResponse
    {
        $this->authorize('restore', $building);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($building, $actor, $request);

        return (new BuildingDetailResource($building->refresh()->loadCount(['floors', 'rooms'])))
            ->additional(['message' => 'Building restored.'])
            ->response();
    }
}
