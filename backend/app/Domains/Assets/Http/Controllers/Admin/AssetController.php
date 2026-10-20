<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\ArchiveAsset;
use App\Domains\Assets\Actions\ConfirmAssetPlacement;
use App\Domains\Assets\Actions\CreateAsset;
use App\Domains\Assets\Actions\RestoreAsset;
use App\Domains\Assets\Actions\UpdateAsset;
use App\Domains\Assets\Http\Requests\IndexAssetsRequest;
use App\Domains\Assets\Http\Requests\StoreAssetRequest;
use App\Domains\Assets\Http\Requests\UpdateAssetRequest;
use App\Domains\Assets\Http\Resources\AssetDetailResource;
use App\Domains\Assets\Http\Resources\AssetListResource;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Services\AssetDirectoryQuery;
use App\Domains\Assets\Services\AssetGuard;
use App\Domains\Assets\Services\AssetLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

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

    /**
     * The custodian's own view: assets currently handed to the caller, no
     * `assets.*` permission required. The one asset listing a Technician or
     * an Administrator-as-custodian can reach outside the Admin-only module
     * (SDD DD-38) — pre-scoped to `assigned_technician_id = $actor->id`, so it
     * can never become a window into the register at large. Teachers, who can
     * never hold custody, are refused outright ({@see AssetPolicy::viewOwnCustody()}).
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewOwnCustody', Asset::class);

        /** @var User $actor */
        $actor = $request->user();

        return AssetListResource::collection(
            $this->custodyOf($actor)
                ->orderBy('asset_tag')
                ->paginate(AssetDirectoryQuery::DEFAULT_PER_PAGE)
                ->withQueryString(),
        );
    }

    /**
     * One asset in the caller's custody, in the same narrow projection as the
     * list — never {@see AssetDetailResource}, whose attachments, QR codes,
     * transfers and audit belong to the register, not to the custodian.
     *
     * `$asset` is an unbound uuid string, resolved *inside* the custody scope:
     * an asset that is not the caller's answers 404 exactly like one that does
     * not exist, so this route cannot be used to probe the register (a record
     * absent from the list is equally unreachable by uuid).
     */
    public function mineShow(Request $request, string $asset): AssetListResource
    {
        $this->authorize('viewOwnCustody', Asset::class);

        /** @var User $actor */
        $actor = $request->user();

        return new AssetListResource(
            $this->custodyOf($actor)->where('uuid', $asset)->firstOrFail(),
        );
    }

    /**
     * The custodian confirms one asset is in its room (DD-115). Resolved inside
     * the custody scope like {@see mineShow()}: someone else's asset is a 404.
     * An asset with no room cannot be confirmed (422); one already confirmed
     * answers 200 unchanged.
     */
    public function confirmPlacement(Request $request, string $asset, ConfirmAssetPlacement $action): AssetListResource
    {
        $this->authorize('confirmOwnPlacement', Asset::class);

        /** @var User $actor */
        $actor = $request->user();

        $record = $this->custodyOf($actor)->where('uuid', $asset)->firstOrFail();

        if ($record->current_room_id === null) {
            throw ValidationException::withMessages([
                'asset' => 'This asset has no room yet. Ask an administrator to record where it is first.',
            ]);
        }

        $action->handle(collect([$record]), $actor, $request);

        return new AssetListResource($record->refresh()->load(['hardwareModel.component.manufacturer', 'supplier', 'currentRoom.floor.building', 'assignedTechnician']));
    }

    /**
     * "Confirm all" (DD-115): every asset in the caller's custody that has a
     * room and is not yet confirmed. Assets with no room are counted, not
     * refused, so one unplaced asset never blocks the rest.
     */
    public function confirmAllPlacements(Request $request, ConfirmAssetPlacement $action): JsonResponse
    {
        $this->authorize('confirmOwnPlacement', Asset::class);

        /** @var User $actor */
        $actor = $request->user();

        $custody = $this->custodyOf($actor)->get();
        $confirmed = $action->handle($custody, $actor, $request);

        return response()->json([
            'message' => $confirmed->isEmpty()
                ? 'Nothing left to confirm.'
                : "{$confirmed->count()} asset(s) confirmed in their rooms.",
            'meta' => [
                'confirmed' => $confirmed->count(),
                'without_room' => $custody->whereNull('current_room_id')->count(),
            ],
        ]);
    }

    /**
     * @return Builder<Asset>
     */
    private function custodyOf(User $actor): Builder
    {
        return Asset::query()
            ->with(['hardwareModel.component.manufacturer', 'supplier', 'currentRoom.floor.building', 'assignedTechnician'])
            ->where('assigned_technician_id', $actor->getKey());
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
