<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Controllers;

use App\Domains\Locations\Http\Requests\LocationLookupRequest;
use App\Domains\Locations\Http\Resources\LocationOptionResource;
use App\Domains\Locations\Policies\RoomPolicy;
use App\Domains\Locations\Services\LocationOptions;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The **narrow location lookup** (SRS FR-LOC-011) — the only part of the location
 * data a non-administrator can reach.
 *
 * The Locations *module* (directory, tree, dashboard, detail pages, all writes)
 * is Administrator-only. But a form still has to let someone name a place: a
 * teacher reporting where a fault is, a technician recording where work happened.
 * This lookup exists for exactly that field and nothing more.
 *
 * What keeps it narrow:
 *  - **Authorized by the consuming workflow**, not by a locations permission —
 *    holding `tickets.create`, `maintenance.view`, `assets.transfer` and the like
 *    opens it ({@see RoomPolicy::selectLocation}).
 *  - **Label-only responses** — building · floor · room, no counts, no custodians,
 *    no blame columns, no timestamps.
 *  - **Selectable locations only** — inactive or archived buildings, floors and
 *    rooms are absent, so nobody can file against a place that is out of service.
 *  - **Bounded** — every response is limited and searchable, never a full estate
 *    dump.
 *
 * Served under `/api/lookups/*`, deliberately *not* `/api/locations/*`, so the
 * route list itself shows that this is a form helper and not the module.
 */
class LocationLookupController extends Controller
{
    /** Selectable rooms, ordered building → floor → room. */
    public function rooms(LocationLookupRequest $request, LocationOptions $options): AnonymousResourceCollection
    {
        return LocationOptionResource::collection($options->rooms($request->validated()));
    }

    /** Selectable buildings, for the first step of a cascading picker. */
    public function buildings(LocationLookupRequest $request, LocationOptions $options): JsonResponse
    {
        return response()->json([
            'data' => $options->buildings()->map(fn (Building $building): array => [
                'id' => $building->uuid,
                'name' => $building->name,
                'code' => $building->code,
            ])->all(),
        ]);
    }

    /** Selectable floors, optionally scoped to one building. */
    public function floors(LocationLookupRequest $request, LocationOptions $options): JsonResponse
    {
        $building = $request->validated('building');

        return response()->json([
            'data' => $options->floors(is_string($building) ? $building : null)
                ->map(fn (Floor $floor): array => [
                    'id' => $floor->uuid,
                    'floor_number' => (int) $floor->floor_number,
                    'name' => $floor->name,
                    'building' => [
                        'id' => $floor->building?->uuid,
                        'name' => $floor->building?->name,
                    ],
                ])->all(),
        ]);
    }
}
