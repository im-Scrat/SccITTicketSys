<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * **Which machines a scanning technician may reach** (SRS FR-QR-012;
 * SDD DD-49, on the DD-40/DD-55 pattern).
 *
 * The authorization spine of the scanned workflow. FR-QR-012 says a Technician's
 * panel "shall be limited to units reachable through their assigned work"; this
 * class is the definition of *reachable*, and it is the only one.
 *
 * ── The scan is not the authorization ──────────────────────────────────────
 *
 * A printed label is a public artifact. Scanning it identifies a machine and
 * nothing else (DD-47): possession of the code appears nowhere below, and no
 * method here takes one. The question this class answers — "does this person
 * have work on this machine?" — has exactly the same answer whether they
 * scanned the sticker, typed the URL, or guessed it.
 *
 * ── One predicate, three uses ──────────────────────────────────────────────
 *
 * {@see scope()} is the whole rule. {@see canReach()} does not re-implement it
 * for a single record — it **runs the same query narrowed to one key**. So a
 * machine absent from a technician's reachable set is unreachable by uuid too,
 * not by convention but by construction: there is no second implementation that
 * could drift, which is the DD-40 argument taken one step further than Tickets
 * or Maintenance took it.
 *
 * ── Why it delegates rather than restates ──────────────────────────────────
 *
 * "Work I own" is already defined twice in this codebase, authoritatively:
 * {@see MaintenanceVisibility::scopeOwn()} owns FR-MNT-011's "assigned to me or
 * created by me", and {@see TicketVisibility::scopeAssigned()} owns the
 * assignment scope. Restating either here would create a third definition of
 * ownership that could disagree with the module it came from — and a
 * disagreement about whose work a machine is would be an IDOR. Both are called,
 * not copied.
 *
 * ── Why an *active* assignment, not merely a readable one ──────────────────
 *
 * `TicketVisibility::READABLE_ASSIGNMENT_STATUSES` keeps a completed or
 * reassigned job readable, because a technician needs their own history. That is
 * right for a ticket page and wrong here: the scan panel is an on-site working
 * surface for a job in progress, so it uses `WRITABLE_ASSIGNMENT_STATUSES` —
 * pending, accepted, in progress, on hold. Finishing a job ends the entitlement
 * to stand at the machine and open its panel; the ticket record stays readable
 * where it belongs.
 */
class ScannedPcAccess
{
    public function __construct(
        private readonly MaintenanceVisibility $maintenance,
        private readonly TicketVisibility $tickets,
    ) {}

    /**
     * Constrain a PC-unit query to the machines this user may reach by scanning.
     *
     * `PcUnit::query()` excludes soft-deleted rows by default, so an archived
     * machine is outside every result here — which is what FR-QR-012's exclusion
     * of archived rows requires, enforced by the query rather than by the
     * resource remembering to omit them.
     *
     * @param  Builder<PcUnit>  $query
     * @return Builder<PcUnit>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->isAdministrator($user)) {
            // FR-QR-012: "an Administrator's is unrestricted."
            return $query;
        }

        if (! $this->hasFloor($user)) {
            // Deny by default: no maintenance or ticket entitlement, no rows —
            // never an unscoped list. A Teacher lands here.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($user): void {
            $inner->whereExists($this->ownOpenMaintenance($user))
                ->orWhereExists($this->ownActiveTicket($user));
        });
    }

    /**
     * May this user reach this specific machine?
     *
     * Deliberately implemented as {@see scope()} narrowed to one key rather than
     * as a parallel set of checks. Two implementations of "which machines" would
     * eventually disagree, and the disagreement would be an IDOR — so there is
     * only one, and this is it with a `whereKey`.
     */
    public function canReach(User $user, PcUnit $pcUnit): bool
    {
        return $this->scope(PcUnit::query(), $user)
            ->whereKey($pcUnit->getKey())
            ->exists();
    }

    /**
     * The permission floor: "may you do this kind of work at all?"
     *
     * Deliberately **not** an `assets.*` check. A Technician holds no asset
     * permission whatsoever (DD-38, SRS §8.4), and the scan panel exists
     * precisely so they can reach the machine they are working on without one
     * (FR-AST-013). Gating this on `assets.view` would either lock out every
     * technician or force a permission grant that reopens the whole Asset
     * module.
     *
     * The floor is a floor, not a fence: clearing it says the caller does
     * maintenance or ticket work somewhere, not that they do it *here*. The
     * reachability predicate is what says here.
     */
    public function hasFloor(User $user): bool
    {
        return $user->hasPermissionTo('maintenance.view')
            || $user->hasPermissionTo('tickets.update');
    }

    /**
     * Constrain a maintenance query to the records that make a machine
     * reachable for this user — the work the panel is *about*.
     *
     * The same delegation {@see ownOpenMaintenance()} uses, exposed so the panel
     * renders exactly the records that granted access and never a superset. An
     * Administrator is unconstrained, matching {@see scope()}.
     *
     * @param  Builder<MaintenanceRecord>  $query
     * @return Builder<MaintenanceRecord>
     */
    public function scopeRelevantMaintenance(Builder $query, User $user): Builder
    {
        $query->whereIn('maintenance_records.status', array_map(
            static fn ($status): string => $status->value,
            MaintenanceVisibility::OPEN_STATUSES,
        ));

        return $this->isAdministrator($user)
            ? $query
            : $this->maintenance->scopeOwn($query, $user);
    }

    /**
     * Constrain a ticket query to the open tickets this user is actively
     * assigned to — the other half of what makes a machine reachable.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeRelevantTickets(Builder $query, User $user): Builder
    {
        $query->whereHas('status', static fn ($status) => $status->where('is_open', true));

        return $this->isAdministrator($user)
            ? $query
            : $this->tickets->scopeAssigned(
                $query,
                $user,
                TicketVisibility::WRITABLE_ASSIGNMENT_STATUSES,
            );
    }

    /* ----------------------------------------------------------- internals */

    /**
     * Open maintenance on this machine that belongs to this user.
     *
     * `scopeOwn()` is `MaintenanceVisibility`'s own expression of FR-MNT-011
     * ("assigned to me **or** created by me"), called rather than copied so the
     * two cannot drift. `OPEN_STATUSES` is that service's constant for the same
     * reason.
     *
     * @return Builder<MaintenanceRecord>
     */
    private function ownOpenMaintenance(User $user): Builder
    {
        $query = MaintenanceRecord::query()
            ->whereColumn('maintenance_records.pc_unit_id', 'pc_units.id')
            ->whereIn('maintenance_records.status', array_map(
                static fn ($status): string => $status->value,
                MaintenanceVisibility::OPEN_STATUSES,
            ));

        return $this->maintenance->scopeOwn($query, $user);
    }

    /**
     * An open ticket on this machine that this user is actively assigned to.
     *
     * Both halves are required. The assignment alone is not enough — a closed
     * ticket is finished work — and an open ticket alone is certainly not
     * enough, or every technician could reach every machine with a fault
     * reported against it.
     *
     * @return Builder<Ticket>
     */
    private function ownActiveTicket(User $user): Builder
    {
        $query = Ticket::query()
            ->whereColumn('tickets.pc_unit_id', 'pc_units.id')
            ->whereHas('status', static fn ($status) => $status->where('is_open', true));

        return $this->tickets->scopeAssigned(
            $query,
            $user,
            TicketVisibility::WRITABLE_ASSIGNMENT_STATUSES,
        );
    }

    /**
     * Read as a role, not a permission, for the reason `TicketVisibility` and
     * `MaintenanceVisibility` both give: permissions answer "may you", the role
     * answers "which sort of participant are you" — and a Technician and an
     * Administrator hold the identical `maintenance.*` set while being entitled
     * to completely different machines.
     */
    private function isAdministrator(User $user): bool
    {
        return $user->role?->slug === 'administrator';
    }
}
