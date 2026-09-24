<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Policies;

use App\Domains\WorkSupport\Services\WorkSupportVisibility;
use App\Models\User;
use App\Models\WorkSupportRequest;

/**
 * Per-record authorization for work support requests (SRS FR-WSR-009/010;
 * SDD DD-54).
 *
 * Like `TicketPolicy` and `MaintenanceRecordPolicy`, this policy answers no
 * question on its own: every method delegates to {@see WorkSupportVisibility} —
 * the same service the list queries use — so a request that never appeared in
 * someone's list is unreachable by uuid too.
 *
 * Ownership rules are policy methods, never permissions. A Technician tracking
 * their own requests and an Administrator deciding anyone's hold the identical
 * `maintenance.*` set; what differs is the rows, and rows are this class's
 * business (Client decision OD-4 — no `wsr.*` permission was invented).
 */
class WorkSupportRequestPolicy
{
    public function __construct(private readonly WorkSupportVisibility $visibility) {}

    /* -------------------------------------------------------------- reads */

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasFloor($actor);
    }

    public function view(User $actor, WorkSupportRequest $request): bool
    {
        return $this->visibility->canSee($actor, $request);
    }

    /** The cross-estate request inbox (FR-WSR-010). Administrator-only. */
    public function viewAdministrative(User $actor): bool
    {
        return $this->visibility->canSeeAdministrative($actor);
    }

    /* ------------------------------------------------------------- writes */

    /**
     * Submit a request.
     *
     * Only the floor is answered here. *Which* machine and *which* maintenance
     * record are decided per-record by `ScannedPcAccess` and `ScannedWorkTargets`
     * inside the action, because a policy with no request in hand cannot see
     * either — and answering "may you submit at all" as though it were "may you
     * submit this" is how a workflow acquires an IDOR.
     */
    public function create(User $actor): bool
    {
        return $this->visibility->hasFloor($actor)
            && $actor->hasPermissionTo('maintenance.update');
    }

    /** Approve, decline, ask for a face-to-face, close. Administrator-only. */
    public function decide(User $actor, WorkSupportRequest $request): bool
    {
        return $this->visibility->canDecide($actor);
    }

    /** Withdraw (FR-WSR-014) — the submitter, or an administrator on their behalf. */
    public function cancel(User $actor, WorkSupportRequest $request): bool
    {
        return $this->visibility->canCancel($actor, $request);
    }

    /** Acknowledge a new schedule (FR-WSR-006) — the submitting technician only. */
    public function acknowledge(User $actor, WorkSupportRequest $request): bool
    {
        return $this->visibility->canAcknowledge($actor, $request);
    }

    /**
     * There is no update path and no delete path, and both are denied
     * explicitly so a future controller cannot acquire one by default.
     *
     * A request's content is what the technician asked for at the moment they
     * asked; editing it after a decision would make the decision describe
     * something else. A request that is no longer needed is *cancelled*, which
     * says so without erasing that it was made.
     */
    public function update(User $actor, WorkSupportRequest $request): bool
    {
        return false;
    }

    public function delete(User $actor, WorkSupportRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $actor, WorkSupportRequest $request): bool
    {
        return false;
    }
}
