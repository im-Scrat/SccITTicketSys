<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Http\Requests\TicketOverviewRequest;
use App\Domains\Analytics\Services\TicketOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/reports/ticket-overview — the Admin Dashboard's analytics
 * (Phase 2.10; SRS FR-DSH-009, FR-RPT-001/002).
 *
 * Authorized three times on purpose: the route group's `can:viewAny,ReportArea`
 * gate, the request's domain checks, and the service before it reads its cache
 * (ReportsAccess documents why a permission-string gate is not enough).
 */
class TicketOverviewController extends Controller
{
    public function __invoke(TicketOverviewRequest $request, TicketOverview $overview): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($overview->build($request->filters(), $actor));
    }
}
