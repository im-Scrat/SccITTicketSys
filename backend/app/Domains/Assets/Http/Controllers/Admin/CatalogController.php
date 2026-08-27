<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Services\AssetOptions;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the asset and PC forms need to render their selects, in one round
 * trip (SRS FR-AST-001).
 *
 * A single endpoint rather than six: the create drawer needs statuses,
 * conditions, categories, manufacturers, suppliers, catalog models and eligible
 * technicians simultaneously, and six parallel requests to open one form is the
 * kind of chattiness that makes a UI feel slow on the modest hardware this
 * product targets (NFR-PERF).
 *
 * Administrator-only, like the rest of the module — the *non-admin* equivalent
 * is the narrow lookup at `/api/lookups/*`, which returns labels and nothing
 * else (SDD DD-38).
 */
class CatalogController extends Controller
{
    public function __invoke(Request $request, AssetOptions $options): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        return response()->json(['data' => $options->catalog()]);
    }
}
