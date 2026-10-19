<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Policies;

use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Models\MaintenanceRecord;
use App\Models\User;

/**
 * Per-record authorization for maintenance (SRS FR-MNT-011; SDD DD-55).
 *
 * Like `TicketPolicy` and unlike {@see AssetPolicy}, this policy does not answer
 * permission questions on its own. Every read delegates to
 * {@see MaintenanceVisibility} — the same service the list queries use — so a
 * record that never appeared in someone's list is unreachable by uuid too.
 *
 * Ownership rules are policy methods, never permissions: a Technician
 * completing their own visit and an Administrator completing anyone's are the
 * same `maintenance.complete` permission applied to different rows.
 */
class MaintenanceRecordPolicy
{
    public function __construct(private readonly MaintenanceVisibility $visibility) {}

    /* ------------------------------------------------------------- reads */

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('maintenance.view');
    }

    public function view(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canSee($actor, $record);
    }

    /**
     * The administrative surface: cross-estate directory, module dashboard,
     * catalogue. Administrator-only regardless of any other ability.
     */
    public function viewAdministrative(User $actor): bool
    {
        return $this->visibility->canSeeAdministrative($actor);
    }

    /** The reference catalogue of maintenance types and checklist templates. */
    public function configureCatalog(User $actor): bool
    {
        return $this->visibility->canConfigureCatalog($actor);
    }

    /** The record's own audit timeline. */
    public function viewAudit(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canSee($actor, $record);
    }

    /* ------------------------------------------------------------ writes */

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('maintenance.create');
    }

    /**
     * Edit the record's detail — diagnosis, root cause, resolution, metrics,
     * schedule.
     *
     * Bounded to open records: a completed visit is the account of what
     * happened, not a draft.
     */
    public function update(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canWork($actor, $record);
    }

    /**
     * Move the record through its lifecycle.
     *
     * Answers only whether this actor may attempt a transition at all; which
     * specific moves are legal, and who may make each one, belongs to
     * `MaintenanceLifecycle`. Note this is not `update()`: a transition *out* of
     * an open state is exactly the operation `canWork()` still permits, while
     * cancelling from `scheduled` must stay available to the owner.
     */
    public function transition(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canWork($actor, $record);
    }

    /** Finish the visit (FR-MNT-003). The checklist and evidence gates live in the lifecycle. */
    public function complete(User $actor, MaintenanceRecord $record): bool
    {
        return $actor->hasPermissionTo('maintenance.complete')
            && $this->visibility->canWork($actor, $record);
    }

    /**
     * Hand the record to a different technician.
     *
     * Administrator-only: distributing work is oversight. A technician changing
     * `technician_id` would be either self-claiming someone else's job or
     * disowning their own, and neither is a maintenance action.
     */
    public function reassign(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canReassign($actor);
    }

    /**
     * The same question with no record in hand — asked while a record is being
     * *created*, to decide whether the caller may name someone other than
     * themselves as the technician.
     */
    public function reassignAny(User $actor): bool
    {
        return $this->visibility->canReassign($actor);
    }

    /**
     * Attach or remove repair evidence and notes (FR-MNT-005/010).
     *
     * Same rule as working the record. Evidence is part of doing the job, so the
     * people doing it are exactly the people who may add it — and once the visit
     * is closed, the evidence set is closed with it.
     */
    public function manageEvidence(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canWork($actor, $record);
    }

    /** Record a hardware replacement against this visit (FR-MNT-006). */
    public function recordReplacement(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canWork($actor, $record);
    }

    /**
     * Archive (soft delete).
     *
     * A Technician holds `maintenance.delete` in the seeded baseline, so the
     * permission cannot be the boundary here; {@see MaintenanceVisibility} caps
     * them at their own records in `scheduled` or `cancelled`, which is work
     * that never happened. Anything describing work that did happen stays.
     */
    public function delete(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canArchive($actor, $record);
    }

    /** Restore an archived record — administrators only. */
    public function restore(User $actor, MaintenanceRecord $record): bool
    {
        return $this->visibility->canSeeAdministrative($actor)
            && $actor->hasPermissionTo('maintenance.delete');
    }

    /**
     * There is no hard-delete path. `forceDelete` is denied to everyone so that
     * a future controller cannot acquire one by default.
     */
    public function forceDelete(User $actor, MaintenanceRecord $record): bool
    {
        return false;
    }
}
