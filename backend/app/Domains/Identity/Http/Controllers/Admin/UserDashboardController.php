<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers\Admin;

use App\Domains\Identity\Services\UserMetrics;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User Management administrative dashboard (SRS v1.2 FR-USER dashboard): totals
 * by status and role, recent registrations, recent logins, and recent
 * administrative actions. Read-only; gated by the directory view permission.
 */
class UserDashboardController extends Controller
{
    public function index(Request $request, UserMetrics $metrics): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json(['data' => $metrics->dashboard()]);
    }
}
