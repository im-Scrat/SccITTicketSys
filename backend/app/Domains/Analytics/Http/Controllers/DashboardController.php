<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers;

use App\Domains\Analytics\Services\DashboardService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The role-aware landing dashboard (SRS FR-DSH-001; SDD §27).
 *
 * One endpoint serves every role: DashboardService selects the layout from the
 * caller's role and each widget's content from their effective permissions, so no
 * per-role route (or client-side filtering of privileged data) is needed. Any
 * authenticated, active account may call it; what comes back is whatever that
 * account is entitled to see.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $dashboard->forUser($user)]);
    }
}
