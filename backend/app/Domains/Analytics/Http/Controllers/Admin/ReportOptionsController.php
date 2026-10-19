<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Services\ReportOptions;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/options — what the report filter toolbar may offer
 * (Phase 2.10). Administrator-only like every report route; the location lists
 * are included only for a viewer who may see locations.
 */
class ReportOptionsController extends Controller
{
    public function __invoke(Request $request, ReportsAccess $access, ReportOptions $options): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($access->canViewTickets($actor), 403);

        return response()->json(['data' => $options->forTickets($actor)]);
    }
}
