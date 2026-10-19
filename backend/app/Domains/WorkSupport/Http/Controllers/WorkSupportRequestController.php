<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Controllers;

use App\Domains\Assets\Services\QrScanResolver;
use App\Domains\Assets\Services\ScannedPcAccess;
use App\Domains\WorkSupport\Actions\SubmitWorkSupportRequest;
use App\Domains\WorkSupport\Http\Requests\CancelWorkSupportRequestRequest;
use App\Domains\WorkSupport\Http\Requests\StoreWorkSupportRequestRequest;
use App\Domains\WorkSupport\Http\Resources\WorkSupportRequestResource;
use App\Domains\WorkSupport\Services\WorkSupportRequestLifecycle;
use App\Domains\WorkSupport\Services\WorkSupportVisibility;
use App\Enums\ScanResult;
use App\Enums\WorkSupportStatus;
use App\Http\Controllers\Controller;
use App\Models\PcUnit;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The technician's side of work support requests
 * (SRS FR-WSR-001/002/009/014; SDD DD-51, DD-54).
 *
 * Two surfaces, and they are not the same shape:
 *
 *  - **Raising** one happens from the scanned workflow, so it is addressed by
 *    the printed code (`POST /qr/{code}/support-requests`) and the machine is
 *    resolved server-side. There is no endpoint that takes a PC identifier.
 *  - **Tracking** them is a page of the technician's own history
 *    (`GET /work-support-requests`), addressed by nothing at all — the rows come
 *    from the session.
 *
 * Every read here is scoped by {@see WorkSupportVisibility}, the same service
 * the policy consults, so a request absent from the list is unreachable by uuid.
 */
class WorkSupportRequestController extends Controller
{
    public function __construct(
        private readonly QrScanResolver $resolver,
        private readonly ScannedPcAccess $access,
        private readonly WorkSupportVisibility $visibility,
        private readonly WorkSupportRequestLifecycle $lifecycle,
        private readonly SubmitWorkSupportRequest $action,
    ) {}

    /**
     * Everything this technician has personally submitted (FR-WSR-009).
     *
     * Uses `scopeOwn`, not `scope`: "my requests" must mean the same thing to an
     * administrator opening their own tracking page as it does to a technician,
     * or the page silently becomes the whole estate for one role.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', WorkSupportRequest::class);

        $query = $this->visibility->scopeOwn(WorkSupportRequest::query(), $user)
            ->with(['items.hardwareModel', 'attachments', 'pcUnit.room', 'maintenanceRecord', 'ticket', 'technician', 'decidedBy', 'cancelledBy']);

        if (($status = $request->query('status')) !== null && in_array($status, WorkSupportStatus::values(), true)) {
            $query->where('status', $status);
        }

        // Undecided first — the ones a technician is waiting on — then newest.
        // A tracking page ordered purely by date buries the open question under
        // a month of settled ones.
        return WorkSupportRequestResource::collection(
            $query->orderByRaw("case when status in ('submitted', 'clarification_requested') then 0 else 1 end")
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        );
    }

    /** One request. The policy answers whose. */
    public function show(Request $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        $this->authorize('view', $workSupportRequest);

        return new WorkSupportRequestResource(
            $workSupportRequest->load(['items.hardwareModel', 'attachments', 'pcUnit.room', 'maintenanceRecord', 'ticket', 'technician', 'decidedBy', 'cancelledBy']),
        );
    }

    /**
     * Raise a request from the scanned workflow (FR-WSR-001).
     *
     * The gates, in order, and each of them independent of the last:
     * the permission floor (FormRequest), the label resolving to a live machine,
     * the reachability predicate that Stage C established, and — inside the
     * action — the maintenance record's own write rule.
     */
    public function store(StoreWorkSupportRequestRequest $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pcUnit = $this->gate($user, $code);

        $created = $this->action->handle(
            $pcUnit,
            $user,
            $request->validated(),
            $request->evidenceFiles(),
            $request,
        );

        return (new WorkSupportRequestResource($created))->response()->setStatusCode(201);
    }

    /**
     * Withdraw a request (FR-WSR-014).
     *
     * A `POST` to a named operation, not a `PATCH` of a status field — the
     * DD-54 rule, applied to the technician's own move as strictly as to the
     * administrator's.
     */
    public function cancel(CancelWorkSupportRequestRequest $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->lifecycle->cancel($workSupportRequest, $user, $request->validated(), $request);

        return new WorkSupportRequestResource(
            $updated->load(['items.hardwareModel', 'attachments', 'pcUnit.room', 'maintenanceRecord', 'ticket', 'technician', 'decidedBy', 'cancelledBy']),
        );
    }

    /** Acknowledge the new schedule on an approved request (FR-WSR-006). */
    public function acknowledge(Request $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        $this->authorize('acknowledge', $workSupportRequest);

        /** @var User $user */
        $user = $request->user();

        $updated = $this->lifecycle->acknowledge($workSupportRequest, $user, $request);

        return new WorkSupportRequestResource(
            $updated->load(['items.hardwareModel', 'attachments', 'pcUnit.room', 'maintenanceRecord', 'ticket', 'technician', 'decidedBy', 'cancelledBy']),
        );
    }

    /* ----------------------------------------------------------- internals */

    /**
     * Resolve the scanned code to a machine this caller may work on.
     *
     * The same three refusals the scan panel and the proof endpoint give, in the
     * same order and the same words — a caller must not be able to learn more
     * about a code by raising a support request against it than by scanning it.
     */
    private function gate(User $user, string $code): PcUnit
    {
        $outcome = $this->resolver->resolve($code);

        if (! $this->access->hasFloor($user)) {
            $this->refuse('not_authorized', 'You do not have access to this equipment workflow.');
        }

        if ($outcome->result === ScanResult::Expired) {
            $this->refuse('label_inactive', 'This label is no longer valid. Ask an administrator for a replacement.');
        }

        if ($outcome->result !== ScanResult::Success) {
            $this->refuse('label_unknown', 'This label is not recognised.');
        }

        $pcUnit = $outcome->pcUnit();

        if ($pcUnit === null) {
            $this->refuse('no_workflow', 'This label is not for a computer unit.');
        }

        // Stage C's predicate, called and not copied.
        $this->authorize('viewScanned', $pcUnit);

        return $pcUnit;
    }

    private function refuse(string $reason, string $message): never
    {
        abort(response()->json([
            'next' => 'refused',
            'reason' => $reason,
            'message' => $message,
        ], 403));
    }
}
