<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers;

use App\Domains\Assets\Http\Requests\AssetLookupRequest;
use App\Domains\Assets\Http\Requests\RoomEquipmentLookupRequest;
use App\Domains\Assets\Http\Resources\AssetOptionResource;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Policies\PcUnitPolicy;
use App\Domains\Assets\Services\AssetOptions;
use App\Domains\Assets\Services\ReportableEquipment;
use App\Domains\Locations\Services\LocationOptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The **narrow equipment lookup** (SDD DD-38) — the only asset data a
 * non-administrator can reach.
 *
 * The Asset Management *module* (dashboard, directory, detail pages, all writes)
 * is Administrator-only. But a form still has to let someone name a machine: a
 * teacher reporting which PC is broken, a technician recording which unit they
 * repaired. This lookup exists for exactly that field and nothing more.
 *
 * What keeps it narrow — the same four properties as the Phase 2.4 location
 * lookup it is modelled on:
 *  - **Authorized by the consuming workflow**, not by an asset permission —
 *    holding `tickets.create`, `maintenance.view` and the like opens it
 *    ({@see AssetPolicy::selectAsset}, {@see PcUnitPolicy::selectPcUnit}).
 *  - **Label-only responses** — an identifier, a readable name, a location. No
 *    status, price, supplier, custodian, warranty, timestamps or audit.
 *  - **Live equipment only** — retired and disposed assets are absent, so nobody
 *    can file a fault against something that has left the estate.
 *  - **Bounded** — `assets` / `pc-units` are limited and searchable, never a
 *    full register dump.
 *
 * **The room-scoped variant** ({@see roomEquipment()}, Phase 2.9, SDD DD-78)
 * keeps the same four properties and narrows them further, because it is what a
 * teacher's report form calls on every room they pick:
 *  - it needs **both** abilities, since it answers with both lists;
 *  - it lists one **selectable** room only, and an unknown, inactive or archived
 *    room answers exactly like an empty one — no existence oracle;
 *  - it lists **in-service, reportable** equipment only
 *    ({@see ReportableEquipment}): no spare stock, no part fitted inside a PC;
 *  - it takes **no search** — nothing the response withholds can be probed;
 *  - it is **throttled** per account (`lookup-equipment`), because walking every
 *    room's list is the one way to assemble a located inventory from it. A
 *    room's in-service equipment — server and storage rooms included — is
 *    listed to anyone who may report a fault there by owner decision.
 *
 * Served under `/api/lookups/*`, deliberately *not* `/api/admin/assets/*`, so
 * the route list itself shows this is a form helper and not the module.
 */
class AssetLookupController extends Controller
{
    /** Selectable serialized assets. */
    public function assets(AssetLookupRequest $request, AssetOptions $options): AnonymousResourceCollection
    {
        return AssetOptionResource::collection(
            $options->lookupAssets($request->validated('search'))
        );
    }

    /** Selectable PC units — the field a fault report needs. */
    public function pcUnits(AssetLookupRequest $request, AssetOptions $options): AnonymousResourceCollection
    {
        return AssetOptionResource::collection(
            $options->lookupPcUnits($request->validated('search'))
        );
    }

    /**
     * The reportable equipment in one room, as two label-only lists:
     * `{"data": {"pc_units": [...], "assets": [...]}}`.
     *
     * No route-model binding: a bound `{room}` would answer 404 for a room that
     * does not exist and 200 for one that does, whatever its state. Resolving
     * through {@see LocationOptions::findSelectable()} instead makes "no such
     * room", "inactive room", "room in an inactive building" and "archived
     * room" one indistinguishable answer — two empty lists.
     */
    public function roomEquipment(
        RoomEquipmentLookupRequest $request,
        AssetOptions $options,
        LocationOptions $locations,
    ): JsonResponse {
        $room = $locations->findSelectable((string) $request->validated('room'));
        $limit = (int) ($request->validated('limit') ?? AssetOptions::ROOM_LIMIT);

        return response()->json([
            'data' => [
                'pc_units' => $room !== null
                    ? AssetOptionResource::collection($options->roomPcUnits($room, $limit))->resolve($request)
                    : [],
                'assets' => $room !== null
                    ? AssetOptionResource::collection($options->roomAssets($room, $limit))->resolve($request)
                    : [],
            ],
        ]);
    }
}
