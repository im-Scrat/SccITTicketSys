<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\ArchivePcUnit;
use App\Domains\Assets\Actions\CreatePcUnit;
use App\Domains\Assets\Actions\RestorePcUnit;
use App\Domains\Assets\Actions\UpdatePcUnit;
use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Domains\Assets\Http\Requests\IndexPcUnitsRequest;
use App\Domains\Assets\Http\Requests\StorePcUnitRequest;
use App\Domains\Assets\Http\Requests\UpdatePcSpecificationRequest;
use App\Domains\Assets\Http\Requests\UpdatePcUnitRequest;
use App\Domains\Assets\Http\Resources\PcSpecificationResource;
use App\Domains\Assets\Http\Resources\PcUnitDetailResource;
use App\Domains\Assets\Http\Resources\PcUnitListResource;
use App\Domains\Assets\Services\AssetDirectoryQuery;
use App\Domains\Assets\Services\AssetGuard;
use App\Http\Controllers\Controller;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * PC unit CRUD and the specification editor (SRS FR-PC-001..003/006).
 *
 * The specification lives on its own endpoint rather than inside `update()`
 * because it is separately audited (`pc_specification_updated`, with a
 * field-level diff) and because the editor is a distinct screen. One audited
 * concern, one write path.
 */
class PcUnitController extends Controller
{
    public function index(IndexPcUnitsRequest $request, AssetDirectoryQuery $directory): AnonymousResourceCollection
    {
        return PcUnitListResource::collection($directory->pcUnits($request->validated()));
    }

    public function show(Request $request, PcUnit $pcUnit, AssetGuard $guard): JsonResponse
    {
        $this->authorize('view', $pcUnit);

        $pcUnit->load([
            'room.floor.building',
            'specification',
            'createdBy',
            'updatedBy',
            'attachments.uploadedBy',
            'qrCodes',
            'componentInstallations.asset.hardwareModel.component',
            'componentInstallations.installedBy',
            'maintenanceRecords.type',
            'maintenanceRecords.technician',
        ]);

        $blockers = $guard->pcUnitBlockers($pcUnit);

        return (new PcUnitDetailResource($pcUnit))
            ->additional(['meta' => [
                'blockers' => $blockers,
                'in_use' => array_sum($blockers) > 0,
            ]])
            ->response();
    }

    public function store(StorePcUnitRequest $request, CreatePcUnit $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $pcUnit = $action->handle($request->validated(), $actor, $request);

        return (new PcUnitDetailResource($pcUnit))
            ->additional(['message' => 'PC unit created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePcUnitRequest $request, PcUnit $pcUnit, UpdatePcUnit $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($pcUnit, $request->validated(), $actor, $request);

        return (new PcUnitDetailResource($pcUnit->load(['room.floor.building', 'specification', 'createdBy', 'updatedBy'])))
            ->additional(['message' => 'PC unit updated.'])
            ->response();
    }

    /** The specification editor (FR-PC-003). */
    public function updateSpecification(
        UpdatePcSpecificationRequest $request,
        PcUnit $pcUnit,
        UpsertPcSpecification $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $specification = $action->handle($pcUnit, $request->validated(), $actor, $request);

        // Always 200. The spec is addressed by its PC's identity, so whether the
        // backing row already existed is an implementation detail — pinning the
        // status stops `JsonResource` inferring 201 from `wasRecentlyCreated` and
        // makes the client branch on something it should not have to know.
        return (new PcSpecificationResource($specification))
            ->additional(['message' => 'Specification updated.'])
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Request $request, PcUnit $pcUnit, ArchivePcUnit $action): JsonResponse
    {
        $this->authorize('delete', $pcUnit);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($pcUnit, $actor, $request);

        return response()->json(['message' => 'PC unit archived.']);
    }

    public function restore(Request $request, PcUnit $pcUnit, RestorePcUnit $action): JsonResponse
    {
        $this->authorize('restore', $pcUnit);

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($pcUnit, $actor, $request);

        return (new PcUnitDetailResource($pcUnit))
            ->additional(['message' => 'PC unit restored.'])
            ->response();
    }
}
