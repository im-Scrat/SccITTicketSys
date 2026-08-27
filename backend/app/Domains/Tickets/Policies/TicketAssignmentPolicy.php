<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Policies;

use App\Enums\AssignmentStatus;
use App\Models\TechnicianAssignment;
use App\Models\User;

/**
 * Per-record authorization for technician assignments (SRS FR-ASN-003/004).
 *
 * The distinction this policy exists to draw: **acting on your own assignment**
 * (accept, decline, start, hold, complete) is not the same authority as
 * **assigning work** (`TicketPolicy::assign`). A technician has the first and,
 * by default, not the second — which is what stops the queue becoming
 * self-service while still letting the assigned technician run their own job.
 *
 * Every ability here additionally requires the assignment to be **active**. A
 * completed assignment keeps the ticket readable for work history and audit
 * (SDD DD-42) but grants no further writes: the job is finished, and reopening
 * it is the Administrator's call.
 */
class TicketAssignmentPolicy
{
    /*
     * No `TicketVisibility` dependency, deliberately: every ability here is
     * answered by the assignment row itself — is this actor the assignee, and is
     * the assignment in a state where the action makes sense. Ticket-level
     * visibility is already settled by `TicketPolicy` before any of these are
     * reached, so consulting it again would be a second opinion on a question
     * that has been asked.
     */

    /** View an assignment record — the assignee or an administrator. */
    public function view(User $actor, TechnicianAssignment $assignment): bool
    {
        if ($this->isAdministrator($actor)) {
            return true;
        }

        return $assignment->technician_id === $actor->getKey();
    }

    /**
     * Accept a pending assignment. Only the assignee, and only while it is still
     * pending — accepting twice is not a thing.
     */
    public function accept(User $actor, TechnicianAssignment $assignment): bool
    {
        return $this->isAssignee($actor, $assignment)
            && $assignment->status === AssignmentStatus::Pending;
    }

    /** Decline a pending assignment, with a reason (FR-ASN-004). */
    public function decline(User $actor, TechnicianAssignment $assignment): bool
    {
        return $this->accept($actor, $assignment);
    }

    /** Start work — from accepted, or straight from pending in practice. */
    public function start(User $actor, TechnicianAssignment $assignment): bool
    {
        return $this->isAssignee($actor, $assignment)
            && in_array($assignment->status, [
                AssignmentStatus::Pending,
                AssignmentStatus::Accepted,
                AssignmentStatus::OnHold,
            ], true);
    }

    /** Put work on hold — only once it has actually started. */
    public function hold(User $actor, TechnicianAssignment $assignment): bool
    {
        return $this->isAssignee($actor, $assignment)
            && $assignment->status === AssignmentStatus::InProgress;
    }

    /** Complete the work, resolving the ticket. */
    public function complete(User $actor, TechnicianAssignment $assignment): bool
    {
        return $this->isAssignee($actor, $assignment)
            && in_array($assignment->status, [
                AssignmentStatus::Accepted,
                AssignmentStatus::InProgress,
                AssignmentStatus::OnHold,
            ], true);
    }

    /** Cancel or reassign — the Administrator's authority, not the assignee's. */
    public function cancel(User $actor, TechnicianAssignment $assignment): bool
    {
        return $actor->hasPermissionTo('tickets.assign');
    }

    private function isAssignee(User $actor, TechnicianAssignment $assignment): bool
    {
        return $assignment->technician_id === $actor->getKey()
            && $actor->hasPermissionTo('tickets.update');
    }

    private function isAdministrator(User $actor): bool
    {
        return $actor->role?->slug === 'administrator';
    }
}
