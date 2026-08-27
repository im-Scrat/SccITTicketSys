<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Policies;

use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\Ticket;
use App\Models\User;

/**
 * Per-record authorization for tickets (SRS FR-TKT-013; SDD DD-40).
 *
 * Unlike `AssetPolicy`, this policy does **not** answer permission questions
 * alone. Every read ability delegates to {@see TicketVisibility} — the same
 * service the list queries use — so a ticket that never appeared in a user's
 * list is also unreachable by uuid. Two implementations of "which tickets" would
 * eventually drift, and the drift would be an IDOR.
 *
 * Ownership rules are policy methods, never permissions: a Teacher confirming
 * their own resolution and an Administrator closing any ticket are the same
 * `tickets.view`/`tickets.update` permissions applied to different rows.
 */
class TicketPolicy
{
    public function __construct(private readonly TicketVisibility $visibility) {}

    /* ------------------------------------------------------------- reads */

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('tickets.view');
    }

    /**
     * Reachable at all — in *some* projection. The controller then asks
     * {@see TicketVisibility::canSeeFull()} which resource to render, so a
     * requester browsing the community feed gets the restricted card and the
     * reporter gets the full record from the same route.
     */
    public function view(User $actor, Ticket $ticket): bool
    {
        return $this->visibility->canSee($actor, $ticket);
    }

    /** The full record — own ticket, assigned ticket, or administrator. */
    public function viewFull(User $actor, Ticket $ticket): bool
    {
        return $this->visibility->canSeeFull($actor, $ticket);
    }

    /**
     * The administrative surface: directory, triage, audit trail, SLA posture.
     * Administrator-only regardless of any other ability.
     */
    public function viewAdministrative(User $actor): bool
    {
        return $actor->role?->slug === 'administrator'
            && $actor->hasPermissionTo('tickets.view');
    }

    /** The requester community feed — technicians are refused (403, not empty). */
    public function viewFeed(User $actor): bool
    {
        return $this->visibility->canSeeFeed($actor);
    }

    public function viewAudit(User $actor, Ticket $ticket): bool
    {
        return $this->viewAdministrative($actor);
    }

    /* ------------------------------------------------------------ writes */

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('tickets.create');
    }

    /**
     * Edit title/description/category. A reporter may correct their own report
     * while it is still `open` — once someone is working it, the description is
     * part of the record they are working from and only an administrator may
     * change it.
     */
    public function update(User $actor, Ticket $ticket): bool
    {
        if ($actor->hasPermissionTo('tickets.update') && $actor->role?->slug === 'administrator') {
            return true;
        }

        return $this->updateOwn($actor, $ticket);
    }

    public function updateOwn(User $actor, Ticket $ticket): bool
    {
        return $ticket->reporter_id === $actor->getKey()
            && $ticket->status?->slug === 'open'
            && $actor->hasPermissionTo('tickets.create');
    }

    /**
     * Move the ticket through its lifecycle. Whether the *specific* transition
     * is legal, and whether this actor may perform it, is decided by
     * `TicketLifecycle` — this only answers whether they may attempt one.
     */
    public function transition(User $actor, Ticket $ticket): bool
    {
        return $this->visibility->canWork($actor, $ticket)
            || $this->isReporterOf($actor, $ticket);
    }

    /** Set or override priority — administrators only (FR-TKT-004). */
    public function changePriority(User $actor, Ticket $ticket): bool
    {
        return $this->viewAdministrative($actor) && $actor->hasPermissionTo('tickets.update');
    }

    /**
     * Assign or reassign a technician.
     *
     * `tickets.assign` is withdrawn from the Technician role baseline, but the
     * permission remains grantable **per-user** — FR-ASN-001's *"(and permitted
     * Technicians)"* is the SRS's own narrow exception for a lead technician who
     * distributes workload. A deputized technician passes this check; an
     * ordinary one does not, so there is no broad self-claim.
     */
    public function assign(User $actor, Ticket $ticket): bool
    {
        return $actor->hasPermissionTo('tickets.assign');
    }

    /** Mark this ticket a duplicate of another (FR-TKT-011). */
    public function markDuplicate(User $actor, Ticket $ticket): bool
    {
        return $this->viewAdministrative($actor) && $actor->hasPermissionTo('tickets.update');
    }

    /* ------------------------------------------- reporter-owned abilities */

    /** Confirm a resolution, closing the ticket (FR-TKT-016). */
    public function confirmResolution(User $actor, Ticket $ticket): bool
    {
        return $this->isReporterOf($actor, $ticket) && $ticket->status?->slug === 'resolved';
    }

    /**
     * Reopen a resolved or closed ticket.
     *
     * The *window* is enforced by `TicketLifecycle` against
     * `tickets.reopen_window_days`, because an expired window is a 422 about
     * timing, not a 403 about permission — the user is entitled, just late.
     */
    public function reopen(User $actor, Ticket $ticket): bool
    {
        if ($this->viewAdministrative($actor) && $actor->hasPermissionTo('tickets.update')) {
            return true;
        }

        return $this->isReporterOf($actor, $ticket)
            && in_array($ticket->status?->slug, ['resolved', 'closed'], true);
    }

    /** Withdraw an own report before anyone has been assigned to it. */
    public function cancelOwn(User $actor, Ticket $ticket): bool
    {
        return $this->isReporterOf($actor, $ticket)
            && $ticket->status?->slug === 'open'
            && $ticket->assigned_technician_id === null;
    }

    /* ---------------------------------------------- participation & files */

    public function comment(User $actor, Ticket $ticket): bool
    {
        return $actor->hasPermissionTo('tickets.comment')
            && $this->visibility->canSee($actor, $ticket);
    }

    /** Post or read an internal note — never a requester. */
    public function commentInternal(User $actor, Ticket $ticket): bool
    {
        return $actor->hasPermissionTo('tickets.comment')
            && $this->visibility->canSeeInternal($actor, $ticket);
    }

    public function vote(User $actor, Ticket $ticket): bool
    {
        return $actor->hasPermissionTo('tickets.vote')
            && $this->visibility->canSee($actor, $ticket);
    }

    /**
     * Attachments are evidence about the fault, so the reporter and the people
     * working it may add them — a community reader may not.
     */
    public function manageAttachments(User $actor, Ticket $ticket): bool
    {
        return $this->isReporterOf($actor, $ticket) || $this->visibility->canWork($actor, $ticket);
    }

    public function viewAttachments(User $actor, Ticket $ticket): bool
    {
        return $this->visibility->canSeeFull($actor, $ticket);
    }

    /* -------------------------------------------------------- lifecycle */

    public function delete(User $actor, Ticket $ticket): bool
    {
        return $actor->hasPermissionTo('tickets.delete');
    }

    public function restore(User $actor, Ticket $ticket): bool
    {
        return $actor->hasPermissionTo('tickets.delete');
    }

    private function isReporterOf(User $actor, Ticket $ticket): bool
    {
        return $ticket->reporter_id === $actor->getKey();
    }
}
