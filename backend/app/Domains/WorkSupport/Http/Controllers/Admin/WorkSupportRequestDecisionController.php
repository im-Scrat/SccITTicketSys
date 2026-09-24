<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Controllers\Admin;

use App\Domains\WorkSupport\Http\Requests\DecideWorkSupportRequestRequest;
use App\Domains\WorkSupport\Http\Resources\WorkSupportRequestResource;
use App\Domains\WorkSupport\Services\WorkSupportRequestLifecycle;
use App\Domains\WorkSupport\Services\WorkSupportVisibility;
use App\Enums\WorkSupportStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The administrator's request-management surface (SRS FR-WSR-005/006/007/008/010).
 *
 * ── Three endpoints, not one with a `status` field ─────────────────────────
 *
 * FR-WSR-004 forbids accepting a status from the client, so each decision is its
 * own named `POST` with its own required evidence:
 *
 *   approve                → a new date (FR-WSR-006)
 *   request-clarification  → a reason to discuss  (FR-WSR-007)
 *   decline                → a mandatory explanation (FR-WSR-008)
 *
 * **There is deliberately no `PATCH /status` anywhere in this domain.** That is
 * not an omission to be tidied up later; it is the requirement.
 *
 * ── Why the `/admin` prefix is not the control ─────────────────────────────
 *
 * `maintenance.view` cannot close this surface — a Technician holds it too — so
 * the route prefix is a convention and
 * {@see WorkSupportVisibility::canSeeAdministrative()} is the boundary, exactly
 * as `MaintenanceDirectoryController` and `TicketPolicy` already do it.
 */
class WorkSupportRequestDecisionController extends Controller
{
    public function __construct(
        private readonly WorkSupportVisibility $visibility,
        private readonly WorkSupportRequestLifecycle $lifecycle,
    ) {}

    /**
     * The inbox (FR-WSR-010).
     *
     * Filterable by workflow state, and ordered so the ones needing an answer
     * come first — an inbox sorted purely by date is a list, not a queue.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAdministrative', WorkSupportRequest::class);

        /** @var User $user */
        $user = $request->user();

        $query = $this->visibility->scope(WorkSupportRequest::query(), $user)
            ->with(['items.hardwareModel', 'attachments', 'pcUnit.room', 'maintenanceRecord', 'ticket', 'technician', 'decidedBy', 'cancelledBy']);

        $status = $request->query('status');

        if ($status === 'pending') {
            // The inbox's own shorthand for "needs attention", read from the
            // enum rather than restated as a literal pair.
            $query->whereIn('status', array_map(
                static fn (WorkSupportStatus $state): string => $state->value,
                WorkSupportStatus::undecided(),
            ));
        } elseif (is_string($status) && in_array($status, WorkSupportStatus::values(), true)) {
            $query->where('status', $status);
        }

        return WorkSupportRequestResource::collection(
            $query->orderByRaw("case when status in ('submitted', 'clarification_requested') then 0 else 1 end")
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate(20)
                ->withQueryString(),
        );
    }

    /** One request with its full job context (FR-WSR-005). */
    public function show(Request $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        $this->authorize('decide', $workSupportRequest);

        return $this->render($workSupportRequest);
    }

    /** **Decision A** — approve and reschedule (FR-WSR-006). */
    public function approve(DecideWorkSupportRequestRequest $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->render(
            $this->lifecycle->approve($workSupportRequest, $user, $request->validated(), $request),
        );
    }

    /** **Decision B** — ask for a face-to-face discussion (FR-WSR-007). */
    public function requestClarification(DecideWorkSupportRequestRequest $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->render(
            $this->lifecycle->requestClarification($workSupportRequest, $user, $request->validated(), $request),
        );
    }

    /** **Decision C** — decline, with a mandatory reason (FR-WSR-008). */
    public function decline(DecideWorkSupportRequestRequest $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->render(
            $this->lifecycle->decline($workSupportRequest, $user, $request->validated(), $request),
        );
    }

    /** Conclude a decided request. */
    public function close(DecideWorkSupportRequestRequest $request, WorkSupportRequest $workSupportRequest): WorkSupportRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->render($this->lifecycle->close($workSupportRequest, $user, $request));
    }

    private function render(WorkSupportRequest $request): WorkSupportRequestResource
    {
        return new WorkSupportRequestResource(
            $request->load(['items.hardwareModel', 'attachments', 'pcUnit.room', 'maintenanceRecord', 'ticket', 'technician', 'decidedBy', 'cancelledBy']),
        );
    }
}
