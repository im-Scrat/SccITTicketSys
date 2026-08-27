<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers;

use App\Domains\Assets\Http\Requests\AssetLookupRequest;
use App\Domains\Assets\Http\Resources\AssetOptionResource;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Policies\PcUnitPolicy;
use App\Domains\Assets\Services\AssetOptions;
use App\Http\Controllers\Controller;
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
 *  - **Bounded and searchable** — never a full register dump.
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
}
