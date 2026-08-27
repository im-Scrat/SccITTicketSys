<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Analytics\Services\AssetMetrics;
use App\Domains\Assets\Services\AssetOptions;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Asset Management module's landing dashboard (SRS FR-AST-011, FR-DSH-003).
 *
 * Distinct from the role dashboard at `/api/dashboard/widgets`: that one answers
 * "what should this person see when they sign in", assembling widgets across
 * every module by permission. This one answers "what is the state of the
 * equipment register", and only an Administrator ever reaches it.
 *
 * Every figure here is a **link target**. The client renders each card as a
 * control that navigates to the directory with the matching filter applied
 * (`?status=in_repair`, `?building=<uuid>`, `?warranty_expiring=90`), which is
 * why buildings and rooms return their uuids alongside their counts — a count
 * you cannot act on is decoration.
 *
 * Composed from the existing {@see AssetMetrics}, not a parallel implementation:
 * the role dashboard and this page read the same aggregates, so they can never
 * disagree about how many assets are in repair.
 */
class AssetDashboardController extends Controller
{
    public function __invoke(Request $request, AssetMetrics $metrics, AssetOptions $options): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $warrantyWindowDays = 90;

        return response()->json([
            'data' => [
                'summary' => $metrics->assetSummary(),
                'by_status' => $metrics->assets()['by_status'],
                'by_building' => $metrics->byBuilding(),
                'by_room' => $metrics->byRoom(),
                'by_category' => $metrics->byCategory(),
                'warranty_expiring' => $metrics->warrantyExpiring($warrantyWindowDays, 10),
                'recently_added' => $metrics->recentlyAdded(10),
                'recently_updated' => $metrics->recentlyUpdated(10),
                'pc_units' => [
                    'by_status' => $metrics->pcUnitStatus(),
                ],
                // Filter vocabularies travel with the dashboard so the directory
                // it links into does not need a second round trip to render its
                // filter chips.
                'filters' => [
                    'statuses' => $options->statusOptions(),
                    'conditions' => $options->conditionOptions(),
                    'categories' => $options->categoryOptions(),
                ],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
