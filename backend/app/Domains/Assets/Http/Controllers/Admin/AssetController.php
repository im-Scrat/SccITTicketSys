<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\ArchiveAsset;
use App\Domains\Assets\Actions\CreateAsset;
use App\Domains\Assets\Actions\RestoreAsset;
use App\Domains\Assets\Actions\UpdateAsset;
use App\Domains\Assets\Http\Requests\IndexAssetsRequest;
use App\Domains\Assets\Http\Requests\StoreAssetRequest;
use App\Domains\Assets\Http\Requests\UpdateAssetRequest;
use App\Domains\Assets\Http\Resources\AssetDetailResource;
use App\Domains\Assets\Http\Resources\AssetListResource;
use App\Domains\Assets\Services\AssetDirectoryQuery;
use App\Domains\Assets\Services\AssetGuard;
use App\Domains\Assets\Services\AssetLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Asset CRUD (SRS FR-AST-002/011/012).
 *
 * Thin controller: authorize → Action → Resource. The directory query owns
 * search/filter/sort/pagination; the Actions own the writes and their audit.
 *
 * The detail response attaches `meta.transitions` — the lifecycle states this
 * asset can legally reach — and `meta.blockers`, so the client can offer only
 * valid choices and warn before an archive that would be refused, rather than
 * discovering both by trial and error.
 */
class AssetController extends Controller
{
    public function index(IndexAssetsRequest $request, AssetDirectoryQuery $directory): AnonymousResourceCollection
    {
        return AssetListResource::collection($directory->assets($request->validated()));
    }

    public function show(
        Request $request,
        Asset $asset,
        AssetGuard $guard,
        AssetLifecycle $lifecycle,
    ): JsonResponse {
        $this->authorize('view', $asset);

        $asset->load([
            'hardwareModel.component.manufacturer',
            'supplier',
            'currentRoom.floor.building',
            'assignedTechnician',
            'createdBy',
            'updatedBy',
            'attachments.uploadedBy',
            'qrCodes',
            'installations.pcUnit',
            'transfers.fromRoom.floor.building',
            'transfers.toRoom.floor.building',
            'transfers.transferredBy',
            'maintenanceRecords.type',
            'maintenanceRecords.technician',
        ]);

        $blockers = $guard->assetBlockers($asset);

        return (new AssetDetailResource($asset))
            ->additional(['meta' => [
                'blockers' => $blockers,
                'in_use' => array_sum($blockers) > 0,
                'transitions' => $lifecycle->availableTransitions($asset),
            ]])
            ->response();
    }

    public function store(StoreAssetRequest $request, CreateAsset $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $asset = $action->handle($request->validated(), $actor, $request);

        return (new AssetDetailResource($asset))
            ->additional(['message' => 'Asset created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAssetRequest $request, Asset $asset, UpdateAsset $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($asset, $request->validated(), $actor, $request);

        return (new AssetDetailResource($asset->load([
            'hardwareModel.component.manufacturer',
            'supplier',
            'currentRoom.floor.building',
            'assignedTechnician',
            'createdBy',
            'updatedBy',
        ])))
            ->additional(['message' => 'Asset updated.'])
            ->response();
    }

    public function destroy(Request $request, Asset $asset, ArchiveAsset $action): JsonResponse
    {
        $this->authorize('delete', $asset);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($asset, $actor, $request);

        return response()->json(['message' => 'Asset archived.']);
    }

    public function restore(Request $request, Asset $asset, RestoreAsset $action): JsonResponse
    {
        $this->authorize('restore', $asset);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($asset, $actor, $request);

        return (new AssetDetailResource($asset))
            ->additional(['message' => 'Asset restored.'])
            ->response();
    }
}
