<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

use App\Domains\Assets\Services\ScannedPcAccess;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * **Which maintenance records a scanning technician may submit proof against**
 * (SRS FR-MNT-009/011/012; SDD DD-50, on the DD-49 pattern).
 *
 * Stage C established one reachability predicate for *machines*. This is the
 * same discipline one level down, for the *jobs* on a machine — and, crucially,
 * it is not a new rule. It composes the two that already exist:
 *
 *   - {@see ScannedPcAccess::scopeRelevantMaintenance()} decides **which rows**:
 *     open records, and — for anyone who is not an administrator — only their
 *     own. It is the identical call the scan panel makes, so the records offered
 *     for selection are exactly the records the panel displayed. A job the panel
 *     did not show can never be submitted against.
 *   - {@see MaintenanceVisibility::canWork()} decides **whether this actor may
 *     write**: `maintenance.update`, plus row ownership, plus an open status.
 *     It is the identical call the maintenance module makes for its own edit,
 *     checklist and evidence paths.
 *
 * Nothing about ownership or workability is restated here. A third definition
 * of "my job" would eventually disagree with the module it was copied from, and
 * a disagreement about whose job a machine's repair is would be an IDOR
 * (NFR-SEC-003).
 *
 * ── List and single-record are the same query ──────────────────────────────
 *
 * {@see find()} is {@see scope()} narrowed by uuid, exactly as
 * `ScannedPcAccess::canReach()` is `scope()` narrowed by key. So a record absent
 * from {@see candidates()} is unreachable by pasting its identifier — by
 * construction rather than by convention, which is the whole of FR-MNT-011.
 *
 * The scoping is by `pc_unit_id` as well, so a record belonging to a *different*
 * machine is refused even when the caller legitimately owns it. The scanned
 * label names the machine; proof of work on machine A must not land on
 * machine B's job.
 */
class ScannedWorkTargets
{
    public function __construct(
        private readonly ScannedPcAccess $access,
        private readonly MaintenanceVisibility $visibility,
    ) {}

    /**
     * The base query: open maintenance on this machine that this actor may see.
     *
     * @return Builder<MaintenanceRecord>
     */
    public function scope(PcUnit $pcUnit, User $user): Builder
    {
        return $this->access->scopeRelevantMaintenance(
            MaintenanceRecord::query()->where('maintenance_records.pc_unit_id', $pcUnit->getKey()),
            $user,
        );
    }

    /**
     * Every record on this machine this actor could submit proof against.
     *
     * Ordered by schedule then id so the chooser is stable between requests —
     * a list that reshuffles under a technician's thumb is how the wrong job
     * gets picked.
     *
     * @return Collection<int, MaintenanceRecord>
     */
    public function candidates(PcUnit $pcUnit, User $user): Collection
    {
        return $this->scope($pcUnit, $user)
            ->with('type:id,name,slug')
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get()
            ->filter(fn (MaintenanceRecord $record): bool => $this->visibility->canWork($user, $record))
            ->values();
    }

    /**
     * One named record, resolved **through** the same scope.
     *
     * Returns null for a record that does not exist, belongs to another
     * technician, sits on another machine, or is already closed — deliberately
     * the same answer for all four, so the caller cannot use this to learn
     * which of them was true.
     *
     * @param  bool  $lock  take a row lock, for the resolve-then-write path
     */
    public function find(PcUnit $pcUnit, User $user, string $uuid, bool $lock = false): ?MaintenanceRecord
    {
        $query = $this->scope($pcUnit, $user)->where('maintenance_records.uuid', $uuid);

        if ($lock) {
            $query->lockForUpdate();
        }

        $record = $query->first();

        return $record !== null && $this->visibility->canWork($user, $record)
            ? $record
            : null;
    }

    /**
     * The open tickets on this machine this actor is actively assigned to.
     *
     * Consulted only when a proof-of-work submission has to open a record and
     * decide whether the work "arose from an assignment" (FR-MNT-009). Delegated
     * to the same `ScannedPcAccess` method the panel renders from, so a ticket a
     * proof links itself to is one the caller was already shown.
     *
     * @return Collection<int, Ticket>
     */
    public function relevantTickets(PcUnit $pcUnit, User $user): Collection
    {
        return $this->access->scopeRelevantTickets(
            Ticket::query()->where('tickets.pc_unit_id', $pcUnit->getKey()),
            $user,
        )->orderBy('tickets.id')->get();
    }
}
