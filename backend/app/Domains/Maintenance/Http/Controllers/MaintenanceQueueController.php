<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Maintenance\Http\Requests\IndexMaintenanceRequest;
use App\Domains\Maintenance\Http\Resources\MaintenanceListResource;
use App\Domains\Maintenance\Services\MaintenanceDirectoryQuery;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A technician's own maintenance work (SRS FR-MNT-007/011).
 *
 * Three lists, because live work, finished work and the preventive horizon read
 * differently and are acted on differently:
 *
 *  - {@see index()} is the queue — open records, overdue first;
 *  - {@see history()} is what they finished or had cancelled, permanently
 *    readable for reference and audit but writable never;
 *  - {@see scheduled()} is the calendar — dated open work, soonest first.
 *
 * All three are scoped by `MaintenanceVisibility::scopeOwn()` inside the query
 * object, so an Administrator opening them sees *their own* records too. "My
 * maintenance" that silently became "all maintenance" for one role would be a
 * page that lies to the other; the estate view lives on the administrative
 * directory, which says so in its name.
 */
class MaintenanceQueueController extends Controller
{
    public function __construct(private readonly MaintenanceDirectoryQuery $directory) {}

    /** Open work owned by the caller — overdue first, then soonest due. */
    public function index(IndexMaintenanceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaintenanceRecord::class);

        /** @var User $user */
        $user = $request->user();

        return MaintenanceListResource::collection(
            $this->directory->queue($request->validated(), $user),
        );
    }

    /** Completed or cancelled work owned by the caller — read-only. */
    public function history(IndexMaintenanceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaintenanceRecord::class);

        /** @var User $user */
        $user = $request->user();

        return MaintenanceListResource::collection(
            $this->directory->history($request->validated(), $user),
        );
    }

    /**
     * The preventive horizon (SRS FR-MNT-007).
     *
     * `scope=all` widens this to the estate, but only for a caller the
     * visibility service says may have it — the query object decides, not the
     * parameter, so a technician passing it gets their own calendar back rather
     * than a 403 on a read they were entitled to make.
     */
    public function scheduled(IndexMaintenanceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaintenanceRecord::class);

        /** @var User $user */
        $user = $request->user();

        return MaintenanceListResource::collection(
            $this->directory->scheduled($request->validated(), $user),
        );
    }
}
