<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

use App\Domains\Tickets\Services\TicketVisibility;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * **The authorization spine of the Maintenance module** (SRS FR-MNT-011;
 * SDD DD-55, on the DD-40 pattern established by {@see TicketVisibility}).
 *
 * Maintenance is the second module whose permission does not separate the
 * roles. `maintenance.*` is seeded to Administrators **and** Technicians in
 * full (SRS §8.4) — a technician who could not create, update and complete
 * maintenance would have no job — so the permission answers "may you do
 * maintenance at all", and this class answers **whose**.
 *
 * Every read path in the domain resolves through here:
 *
 *   - list / queue / history / directory  ->  {@see scope()} constrains the query
 *   - a single record                     ->  {@see canSee()} answers the same question
 *
 * Both derive from {@see levelFor()}, so a record a technician cannot find in a
 * list is equally unreachable by pasting its uuid. That equivalence is the
 * entire reason this is one class: two implementations of "which records" would
 * eventually disagree, and the disagreement would be an IDOR (NFR-SEC-003).
 *
 * ── The two levels ─────────────────────────────────────────────────────────
 *
 * **Administrator** — every record.
 *
 * **Everyone else holding `maintenance.view`** — the records they were assigned
 * (`technician_id`) **or** opened (`created_by`), and no others. FR-MNT-011
 * names both, and they are genuinely different people whenever an administrator
 * schedules work for a technician, or a technician opens a record that is later
 * reassigned.
 *
 * There is deliberately no third, redacted level. Tickets needed one because a
 * Teacher must be able to discover another requester's ticket to avoid filing a
 * duplicate (DD-41). Nothing in FR-MNT asks a technician to browse maintenance
 * they are not doing, so the honest answer is "no rows", not "fewer fields".
 *
 * ── Why the role check, and why it is only for administrators ──────────────
 *
 * A Teacher holds no `maintenance.*` permission at all, so they are refused by
 * the permission check before scope is ever considered. The role check below
 * therefore only has to identify the one role that transcends row scope. Anyone
 * else who is granted `maintenance.view` through a per-user override
 * (FR-USER-004) lands in the row-scoped branch, which is the safe default: a
 * deputy sees their own work, not the estate's.
 */
class MaintenanceVisibility
{
    public const LEVEL_NONE = 'none';

    /** The full record: own work, or administrator. */
    public const LEVEL_FULL = 'full';

    /**
     * Statuses in which a record is still being worked.
     *
     * Write abilities are bounded by this rather than by ownership alone: a
     * completed record is the account of what happened, and editing it after
     * the fact would make the audit trail negotiable.
     *
     * @var list<MaintenanceStatus>
     */
    public const OPEN_STATUSES = [
        MaintenanceStatus::Scheduled,
        MaintenanceStatus::InProgress,
        MaintenanceStatus::OnHold,
    ];

    /**
     * Statuses a technician may archive their own record from.
     *
     * Approved ceiling (Client decision, 2026-08-28): `scheduled` is work that
     * never started and `cancelled` is work that was called off, so removing
     * either destroys no account of anything. `in_progress`, `on_hold` and
     * `completed` are refused — a record that describes work actually performed
     * is audit material, and the person accountable for it is the last one who
     * should be able to make it disappear. An Administrator is not bound by
     * this; they hold the oversight the restriction exists to protect.
     *
     * @var list<MaintenanceStatus>
     */
    public const TECHNICIAN_ARCHIVABLE_STATUSES = [
        MaintenanceStatus::Scheduled,
        MaintenanceStatus::Cancelled,
    ];

    /**
     * Constrain a maintenance query to what this user may see.
     *
     * @param  Builder<MaintenanceRecord>  $query
     * @return Builder<MaintenanceRecord>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->isAdministrator($user)) {
            return $query;
        }

        if (! $user->hasPermissionTo('maintenance.view')) {
            // Deny by default: no permission, no rows — never an unscoped list.
            return $query->whereRaw('1 = 0');
        }

        return $this->constrainToOwn($query, $user);
    }

    /**
     * Constrain a query to this user's own records regardless of role.
     *
     * Used by the technician surfaces, which stay personal even when an
     * Administrator opens them — "my maintenance" must mean the same thing to
     * everyone, or the page lies to exactly one role.
     *
     * @param  Builder<MaintenanceRecord>  $query
     * @return Builder<MaintenanceRecord>
     */
    public function scopeOwn(Builder $query, User $user): Builder
    {
        return $this->constrainToOwn($query, $user);
    }

    /**
     * The projection this user gets for this record — the single source of
     * truth {@see canSee()} and every resource selection derive from.
     *
     * @return self::LEVEL_*
     */
    public function levelFor(User $user, MaintenanceRecord $record): string
    {
        if ($this->isAdministrator($user)) {
            return self::LEVEL_FULL;
        }

        if (! $user->hasPermissionTo('maintenance.view')) {
            return self::LEVEL_NONE;
        }

        return $this->owns($user, $record) ? self::LEVEL_FULL : self::LEVEL_NONE;
    }

    /** May this user reach this record at all? */
    public function canSee(User $user, MaintenanceRecord $record): bool
    {
        return $this->levelFor($user, $record) !== self::LEVEL_NONE;
    }

    /**
     * May this user change the record — edit its detail, tick its checklist,
     * attach evidence, move its status?
     *
     * Read access outlives completion, because a technician needs their own
     * work history for reference and audit (the DD-42 stance, restated for
     * maintenance). Write access does not: once the visit is finished, the
     * record is the account of it.
     */
    public function canWork(User $user, MaintenanceRecord $record): bool
    {
        if (! $user->hasPermissionTo('maintenance.update')) {
            return false;
        }

        if (! $this->canSee($user, $record)) {
            return false;
        }

        return in_array($record->status, self::OPEN_STATUSES, true);
    }

    /**
     * May this user reassign the record to a different technician?
     *
     * Administrator-only, by the same reasoning that withdrew `tickets.assign`
     * from the Technician baseline in Phase 2.6: distributing work is oversight,
     * not fieldwork. There is no `maintenance.assign` permission in the seeded
     * matrix and this decision deliberately does not invent one — the matrix is
     * the Client's, and a new permission would change it.
     */
    public function canReassign(User $user): bool
    {
        return $this->isAdministrator($user) && $user->hasPermissionTo('maintenance.update');
    }

    /** May this user archive (soft delete) this record? */
    public function canArchive(User $user, MaintenanceRecord $record): bool
    {
        if (! $user->hasPermissionTo('maintenance.delete')) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        return $this->canSee($user, $record)
            && in_array($record->status, self::TECHNICIAN_ARCHIVABLE_STATUSES, true);
    }

    /**
     * The administrative surface: the cross-estate directory, the module
     * dashboard, the reference catalogue of types and checklist templates.
     *
     * Role-gated, because `maintenance.view` cannot close it — a Technician
     * holds it too. The `/admin` route prefix is a convention here, not the
     * control (the Phase 2.6 `TicketPolicy::viewAdministrative()` stance).
     */
    public function canSeeAdministrative(User $user): bool
    {
        return $this->isAdministrator($user) && $user->hasPermissionTo('maintenance.view');
    }

    /**
     * May this user edit the maintenance types and checklist templates?
     *
     * Reuses `maintenance.update` plus the administrator role rather than a new
     * `maintenance.configure` permission (Client decision, 2026-08-28). Editing
     * the catalogue every technician's checklist is issued from is site
     * administration, and the existing matrix already expresses that with the
     * role.
     */
    public function canConfigureCatalog(User $user): bool
    {
        return $this->isAdministrator($user) && $user->hasPermissionTo('maintenance.update');
    }

    /* --------------------------------------------------------- internals */

    /**
     * The row-scope predicate, in one place.
     *
     * `technician_id` **or** `created_by`, exactly as FR-MNT-011 words it. Kept
     * private and shared so {@see scope()} and {@see owns()} cannot drift into
     * two different definitions of "mine".
     *
     * @param  Builder<MaintenanceRecord>  $query
     * @return Builder<MaintenanceRecord>
     */
    private function constrainToOwn(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('maintenance_records.technician_id', $user->getKey())
                ->orWhere('maintenance_records.created_by', $user->getKey());
        });
    }

    /** The single-record form of {@see constrainToOwn()}. */
    private function owns(User $user, MaintenanceRecord $record): bool
    {
        return $record->technician_id === $user->getKey()
            || $record->created_by === $user->getKey();
    }

    /**
     * Read as a role, not a permission, for the reason TicketVisibility gives:
     * permissions answer "may you", the role answers "which sort of participant
     * are you" — and a technician and an administrator hold the identical
     * `maintenance.*` set while being entitled to completely different rows.
     */
    private function isAdministrator(User $user): bool
    {
        return $user->role?->slug === 'administrator';
    }
}
