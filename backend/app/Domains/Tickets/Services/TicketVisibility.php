<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Enums\AssignmentStatus;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * **The authorization spine of Ticket Management** (SRS FR-TKT-013; SDD DD-40).
 *
 * Locations (DD-28) and Assets (DD-38) close themselves to non-administrators by
 * *withdrawing the permission*: the module is site administration, so nobody
 * else needs any of it. Tickets cannot work that way — a Teacher must report and
 * track, a Technician must work what they are given, and **all three roles hold
 * `tickets.view` today**. So the permission answers "may you see tickets at
 * all"; this class answers **which ones**.
 *
 * Every read path in the domain resolves through here:
 *
 *   - list / feed / search  ->  {@see scope()} constrains the query
 *   - a single record       ->  {@see canSee()} answers the same question
 *
 * Both are derived from {@see levelFor()}, so a ticket that a user cannot find
 * in a list is also unreachable by pasting its uuid. That equivalence is the
 * whole point of putting this in one class: two implementations of "which
 * tickets" would eventually disagree, and the disagreement would be an IDOR.
 *
 * ── The three levels ───────────────────────────────────────────────────────
 *
 * **Administrator** — every ticket, full projection.
 *
 * **Technician** — only tickets they hold an assignment row for, and never
 * unrelated tickets, *notwithstanding* their `tickets.view` permission. Read
 * access outlives the assignment (a technician needs their own work history for
 * maintenance reference, repair evidence and audit) but write access does not
 * ({@see canWork()}). A **declined** assignment is excluded from both: declining
 * is an explicit refusal of the work, so there is no work history to reference.
 *
 * **Teacher / Requester** — their own tickets in full, plus a *restricted
 * community projection* of everyone else's, which is what makes duplicate
 * discovery possible (UCS-02) without exposing internal notes, technician
 * detail, SLA posture or attachments. The redaction lives in
 * `TicketFeedResource`, whose shape cannot express those fields; this class
 * decides only *whether* a row is reachable and *in which* projection.
 */
class TicketVisibility
{
    public const LEVEL_NONE = 'none';

    /** Full record: own ticket, assigned ticket, or administrator. */
    public const LEVEL_FULL = 'full';

    /** Restricted community card — another requester's ticket. */
    public const LEVEL_COMMUNITY = 'community';

    /**
     * Assignment statuses that grant a technician continued **read** access.
     *
     * Deliberately excludes `declined`: refusing an assignment is not
     * participation, so it leaves no work history worth referencing. Everything
     * else — including `completed`, `reassigned` and `cancelled` — is retained,
     * because a technician who actually touched a ticket needs to be able to
     * look back at it.
     *
     * @var list<string>
     */
    public const READABLE_ASSIGNMENT_STATUSES = [
        AssignmentStatus::Pending->value,
        AssignmentStatus::Accepted->value,
        AssignmentStatus::InProgress->value,
        AssignmentStatus::OnHold->value,
        AssignmentStatus::Completed->value,
        AssignmentStatus::Reassigned->value,
        AssignmentStatus::Cancelled->value,
    ];

    /**
     * Assignment statuses that additionally grant **write** access — status
     * transitions, internal notes and the assignment actions themselves.
     *
     * @var list<string>
     */
    public const WRITABLE_ASSIGNMENT_STATUSES = [
        AssignmentStatus::Pending->value,
        AssignmentStatus::Accepted->value,
        AssignmentStatus::InProgress->value,
        AssignmentStatus::OnHold->value,
    ];

    /**
     * Constrain a ticket query to what this user may see.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->isAdministrator($user)) {
            return $query;
        }

        if (! $user->hasPermissionTo('tickets.view')) {
            // Deny-by-default: no permission, no rows — never an unscoped list.
            return $query->whereRaw('1 = 0');
        }

        if ($this->isTechnician($user)) {
            return $query->whereIn('tickets.id', $this->assignedTicketIds($user));
        }

        // Requester: everything not soft-deleted is discoverable, but only their
        // own rows resolve to the full projection (see levelFor()).
        return $query;
    }

    /**
     * Constrain a query to **only** the tickets this user reported.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOwn(Builder $query, User $user): Builder
    {
        return $query->where('tickets.reporter_id', $user->getKey());
    }

    /**
     * Constrain a query to a technician's assignments.
     *
     * @param  Builder<Ticket>  $query
     * @param  list<string>|null  $statuses  defaults to the readable set
     * @return Builder<Ticket>
     */
    public function scopeAssigned(Builder $query, User $user, ?array $statuses = null): Builder
    {
        return $query->whereIn('tickets.id', $this->assignedTicketIds($user, $statuses));
    }

    /**
     * The projection this user gets for this ticket — the single source of truth
     * that {@see canSee()} and every resource selection derive from.
     *
     * @return self::LEVEL_*
     */
    public function levelFor(User $user, Ticket $ticket): string
    {
        if ($this->isAdministrator($user)) {
            return self::LEVEL_FULL;
        }

        if (! $user->hasPermissionTo('tickets.view')) {
            return self::LEVEL_NONE;
        }

        if ($this->isTechnician($user)) {
            // A technician's access is defined entirely by their assignment
            // history. No assignment row, no access — the `tickets.view`
            // permission grants nothing on its own.
            return $this->hasReadableAssignment($user, $ticket)
                ? self::LEVEL_FULL
                : self::LEVEL_NONE;
        }

        if ($ticket->reporter_id === $user->getKey()) {
            return self::LEVEL_FULL;
        }

        // Another requester's ticket: reachable, but only as a community card.
        return self::LEVEL_COMMUNITY;
    }

    /** May this user reach this ticket at all, in any projection? */
    public function canSee(User $user, Ticket $ticket): bool
    {
        return $this->levelFor($user, $ticket) !== self::LEVEL_NONE;
    }

    /** May this user see the *full* record rather than the community card? */
    public function canSeeFull(User $user, Ticket $ticket): bool
    {
        return $this->levelFor($user, $ticket) === self::LEVEL_FULL;
    }

    /**
     * May this user work the ticket — transition its status, post internal
     * notes, act on the assignment?
     *
     * Distinct from read access on purpose: a completed assignment keeps the
     * ticket readable for history and audit, but every write ability lapses.
     */
    public function canWork(User $user, Ticket $ticket): bool
    {
        if ($this->isAdministrator($user)) {
            return $user->hasPermissionTo('tickets.update');
        }

        if (! $this->isTechnician($user) || ! $user->hasPermissionTo('tickets.update')) {
            return false;
        }

        return $this->hasActiveAssignment($user, $ticket);
    }

    /**
     * May this user read the community feed?
     *
     * Technicians are excluded by design: their surface is their assigned queue,
     * not the requester community. The endpoint returns **403 rather than an
     * empty list**, because silence would misrepresent the feed as empty.
     */
    public function canSeeFeed(User $user): bool
    {
        return $user->hasPermissionTo('tickets.view') && ! $this->isTechnician($user);
    }

    /** May this user read or write `is_internal` comments? */
    public function canSeeInternal(User $user, Ticket $ticket): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }

        return $this->isTechnician($user) && $this->hasReadableAssignment($user, $ticket);
    }

    /* --------------------------------------------------------- internals */

    /**
     * Ticket ids this technician holds an assignment for.
     *
     * Returned as a subquery rather than an array of ids so the constraint stays
     * in the database — a technician with a long history must not turn every
     * list into a giant `IN (...)` literal.
     *
     * @param  list<string>|null  $statuses
     * @return Builder<TechnicianAssignment>
     */
    private function assignedTicketIds(User $user, ?array $statuses = null)
    {
        return TechnicianAssignment::query()
            ->select('ticket_id')
            ->where('technician_id', $user->getKey())
            ->whereIn('status', $statuses ?? self::READABLE_ASSIGNMENT_STATUSES);
    }

    private function hasReadableAssignment(User $user, Ticket $ticket): bool
    {
        return TechnicianAssignment::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('technician_id', $user->getKey())
            ->whereIn('status', self::READABLE_ASSIGNMENT_STATUSES)
            ->exists();
    }

    private function hasActiveAssignment(User $user, Ticket $ticket): bool
    {
        return TechnicianAssignment::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('technician_id', $user->getKey())
            ->whereIn('status', self::WRITABLE_ASSIGNMENT_STATUSES)
            ->exists();
    }

    /**
     * Role checks read the role slug rather than a permission, because the
     * distinction being drawn here *is* the role: a technician and a teacher can
     * hold the identical `tickets.view` permission and still be entitled to
     * completely different rows. Permissions answer "may you"; the role answers
     * "which sort of participant are you".
     */
    private function isAdministrator(User $user): bool
    {
        return $user->role?->slug === 'administrator';
    }

    private function isTechnician(User $user): bool
    {
        return $user->role?->slug === 'technician';
    }
}
