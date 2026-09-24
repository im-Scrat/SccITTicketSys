<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers\Admin;

use App\Domains\Maintenance\Http\Requests\IndexMaintenanceRequest;
use App\Domains\Maintenance\Http\Resources\MaintenanceListResource;
use App\Domains\Maintenance\Services\MaintenanceDirectoryQuery;
use App\Domains\Maintenance\Services\MaintenanceMetrics;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Administrator's cross-estate maintenance surface (SRS FR-MNT-011,
 * FR-DSH-003).
 *
 * `can:maintenance.view` cannot close this route — a Technician holds it too —
 * so **every method authorizes `viewAdministrative` explicitly**. The `/admin`
 * prefix is a naming convention here, not the control. That is the same stance
 * `TicketDirectoryController` takes, and for the same reason: a surface whose
 * only protection is where its URL sits is protected by nothing.
 */
class MaintenanceDirectoryController extends Controller
{
    public function __construct(
        private readonly MaintenanceDirectoryQuery $directory,
        private readonly MaintenanceMetrics $metrics,
    ) {}

    /** Every maintenance record, every filter. */
    public function index(IndexMaintenanceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAdministrative', MaintenanceRecord::class);

        /** @var User $user */
        $user = $request->user();

        return MaintenanceListResource::collection(
            $this->directory->directory($request->validated(), $user),
        );
    }

    /**
     * The module dashboard.
     *
     * Every figure is a live aggregate over `maintenance_records`; nothing here
     * is stored or cached, so a tile and the directory it links to can never
     * disagree about what is overdue.
     */
    public function dashboard(): JsonResponse
    {
        $this->authorize('viewAdministrative', MaintenanceRecord::class);

        return response()->json([
            'data' => [
                'cadence' => $this->metrics->cadence(),
                'posture' => $this->metrics->posture(),
                'throughput' => $this->metrics->throughput(),
                'status_mix' => $this->metrics->statusMix(),
                'type_mix' => $this->metrics->typeMix(),
                'technician_workload' => $this->metrics->technicianWorkload(),
            ],
        ]);
    }
}
