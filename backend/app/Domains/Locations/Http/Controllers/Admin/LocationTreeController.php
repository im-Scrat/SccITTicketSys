<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Controllers\Admin;

use App\Domains\Locations\Http\Resources\LocationTreeResource;
use App\Domains\Locations\Services\LocationMetrics;
use App\Http\Controllers\Controller;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The location tree that backs the explorer (SRS FR-LOC-001..003): buildings and
 * their floors with per-level counts. Rooms load per floor on expand, so the
 * payload stays bounded on a large estate (NFR-PERF-008).
 */
class LocationTreeController extends Controller
{
    public function __invoke(Request $request, LocationMetrics $metrics): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Building::class);

        return LocationTreeResource::collection($metrics->tree());
    }
}
