<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Controllers\Admin;

use App\Domains\Locations\Services\LocationMetrics;
use App\Http\Controllers\Controller;
use App\Models\Building;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Location Management dashboard metrics (SRS FR-LOC): estate totals per level,
 * the room-type mix, occupancy, and recent location administration activity.
 * Every figure is a database aggregate ({@see LocationMetrics}).
 */
class LocationDashboardController extends Controller
{
    public function __invoke(Request $request, LocationMetrics $metrics): JsonResponse
    {
        $this->authorize('viewAny', Building::class);

        return response()->json(['data' => $metrics->dashboard()]);
    }
}
