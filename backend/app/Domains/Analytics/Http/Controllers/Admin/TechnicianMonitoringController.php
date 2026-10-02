<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Services\TechnicianMonitoring;
use App\Domains\Analytics\Services\TechnicianMonitoringAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2.9 — technician monitoring for administrators (recorded and
 * calculated figures only; see TechnicianMonitoring).
 *
 * Authorized in each action through TechnicianMonitoringAccess, never a
 * `can:` permission-string middleware (the per-user-grant trap that class
 * documents). The detail route takes a user uuid as a plain string: a uuid
 * that is not a technician answers 404 exactly like one that does not exist,
 * so the endpoint cannot be used to discover other accounts' roles.
 */
class TechnicianMonitoringController extends Controller
{
    public function __construct(
        private readonly TechnicianMonitoring $monitoring,
        private readonly TechnicianMonitoringAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $this->monitoring->overview()]);
    }

    public function show(Request $request, string $technician): JsonResponse
    {
        $this->authorizeView($request);

        $user = $this->monitoring->technicians()->where('uuid', $technician)->first();

        abort_if($user === null, 404);

        return response()->json(['data' => $this->monitoring->detail($user)]);
    }

    private function authorizeView(Request $request): void
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->access->canView($actor), 403);
    }
}
