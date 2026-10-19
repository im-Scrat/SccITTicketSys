<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\AssignAssetTechnician;
use App\Domains\Assets\Actions\ChangeAssetStatus;
use App\Domains\Assets\Actions\TransferAsset;
use App\Domains\Assets\Http\Requests\AssignTechnicianRequest;
use App\Domains\Assets\Http\Requests\ChangeAssetStatusRequest;
use App\Domains\Assets\Http\Requests\TransferAssetRequest;
use App\Domains\Assets\Http\Resources\AssetDetailResource;
use App\Domains\Assets\Services\AssetLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The three lifecycle operations that are *not* plain edits: status change,
 * room transfer and custodian assignment (SRS FR-AST-005/006, FR-AST-002).
 *
 * Each has its own endpoint because each writes a history row alongside the
 * change — `asset_status_history`, `asset_transfers`, and a distinct audit
 * action respectively. Folding them into `PUT /assets/{uuid}` would let an
 * ordinary edit move an asset without leaving a trace of the move, which is
 * exactly the guarantee FR-AST-005/006 exist to make.
 *
 * Authorization is per-request rather than per-route, because
 * {@see ChangeAssetStatusRequest} needs the *target* status to decide between
 * `assets.update` and `assets.dispose` (SDD DD-33).
 */
class AssetActionController extends Controller
{
    /** Move the asset through its lifecycle (FR-AST-005). */
    public function changeStatus(
        ChangeAssetStatusRequest $request,
        Asset $asset,
        ChangeAssetStatus $action,
        AssetLifecycle $lifecycle,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle(
            $asset,
            $request->target(),
            $actor,
            $request,
            $request->validated('reason'),
        );

        $asset->load(['hardwareModel.component.manufacturer', 'currentRoom.floor.building', 'assignedTechnician']);

        return (new AssetDetailResource($asset))
            ->additional([
                'message' => 'Status updated to '.$asset->status->label().'.',
                'meta' => ['transitions' => $lifecycle->availableTransitions($asset)],
            ])
            ->response();
    }

    /** Move the asset to another room (FR-AST-006). */
    public function transfer(TransferAssetRequest $request, Asset $asset, TransferAsset $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $roomUuid = $request->validated('room');
        $target = is_string($roomUuid) && $roomUuid !== ''
            ? Room::query()->where('uuid', $roomUuid)->firstOrFail()
            : null;

        $action->handle(
            $asset,
            $target,
            $actor,
            $request,
            $request->validated('reason'),
            $request->validated('remarks'),
        );

        $asset->load(['hardwareModel.component.manufacturer', 'currentRoom.floor.building', 'assignedTechnician']);

        return (new AssetDetailResource($asset))
            ->additional([
                'message' => $target !== null
                    ? 'Asset moved to '.$target->name.'.'
                    : 'Asset removed from its room.',
            ])
            ->response();
    }

    /** Hand the asset to a custodian, or take it back (FR-AST-002). */
    public function assign(AssignTechnicianRequest $request, Asset $asset, AssignAssetTechnician $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $uuid = $request->validated('technician');
        $technician = is_string($uuid) && $uuid !== ''
            ? User::query()->where('uuid', $uuid)->firstOrFail()
            : null;

        $action->handle($asset, $technician, $actor, $request);

        $asset->load(['hardwareModel.component.manufacturer', 'currentRoom.floor.building', 'assignedTechnician']);

        return (new AssetDetailResource($asset))
            ->additional([
                'message' => $technician !== null
                    ? 'Asset assigned to '.$technician->fullName().'.'
                    : 'Asset unassigned.',
            ])
            ->response();
    }
}
