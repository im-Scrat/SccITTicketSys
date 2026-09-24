<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Services;

use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * **The authorization spine of work support requests** (SRS FR-WSR-009/010;
 * SDD DD-40 pattern, as applied by {@see TicketVisibility} and
 * {@see MaintenanceVisibility}).
 *
 * The third module to follow the same rule, and for the third time the reason
 * is that the *permission* does not separate the roles: no `wsr.*` permission
 * exists in the Client's §8.4 matrix and inventing one would change that matrix
 * (Client decision OD-4, 2026-08-29). So `maintenance.*` answers "may you take
 * part in this workflow at all", and this class answers **whose requests**.
 *
 * ── Two levels, and deliberately no third ──────────────────────────────────
 *
 * **Administrator** — every request. Deciding them is the whole of FR-WSR-010.
 *
 * **Everyone else holding the floor** — the requests they personally submitted
 * (`technician_id`), and no others. FR-WSR-009 words it exactly that way:
 * *"everything they personally submitted … a technician shall reach their own
 * records and no others, in lists **and** by direct identifier."*
 *
 * There is no redacted middle level. Tickets needed one so a Teacher could
 * discover a duplicate (DD-41); nothing in FR-WSR asks a technician to see that
 * a colleague's request exists, and the honest answer is no rows rather than
 * fewer fields.
 *
 * ── One predicate, two uses ────────────────────────────────────────────────
 *
 * {@see scope()} is the rule. {@see canSee()} does not re-derive it — it asks
 * the same question of one row. A request absent from a technician's list is
 * therefore unreachable by pasting its uuid, by construction rather than by two
 * implementations agreeing (NFR-SEC-003).
 */
class WorkSupportVisibility
{
    public const LEVEL_NONE = 'none';

    public const LEVEL_FULL = 'full';

    /**
     * The permission floor for the whole workflow (Client decision OD-4).
     *
     * `maintenance.view` and nothing narrower: a support request is raised from
     * a maintenance job and read beside it, so the workflow that owns the job
     * owns the entitlement. Deliberately **not** an `assets.*` check — a
     * Technician holds none (DD-38) — and deliberately **not** a new
     * permission.
     */
    public function hasFloor(User $user): bool
    {
        return $user->hasPermissionTo('maintenance.view');
    }

    /**
     * Constrain a request query to what this user may see.
     *
     * @param  Builder<WorkSupportRequest>  $query
     * @return Builder<WorkSupportRequest>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->isAdministrator($user)) {
            return $query;
        }

        if (! $this->hasFloor($user)) {
            // Deny by default: no floor, no rows — never an unscoped list.
            // A Teacher lands here.
            return $query->whereRaw('1 = 0');
        }

        return $query->where('work_support_requests.technician_id', $user->getKey());
    }

    /**
     * Constrain a query to this user's own requests regardless of role.
     *
     * The technician tracking surface uses this rather than {@see scope()}, so
     * "my requests" means the same thing to an administrator opening their own
     * page as it does to a technician. A page whose meaning changes with the
     * reader's role is a page that lies to exactly one of them.
     *
     * @param  Builder<WorkSupportRequest>  $query
     * @return Builder<WorkSupportRequest>
     */
    public function scopeOwn(Builder $query, User $user): Builder
    {
        return $query->where('work_support_requests.technician_id', $user->getKey());
    }

    /**
     * The projection this user gets for this request.
     *
     * @return self::LEVEL_*
     */
    public function levelFor(User $user, WorkSupportRequest $request): string
    {
        if ($this->isAdministrator($user)) {
            return self::LEVEL_FULL;
        }

        if (! $this->hasFloor($user)) {
            return self::LEVEL_NONE;
        }

        return $request->technician_id === $user->getKey() ? self::LEVEL_FULL : self::LEVEL_NONE;
    }

    /** May this user reach this request at all? */
    public function canSee(User $user, WorkSupportRequest $request): bool
    {
        return $this->levelFor($user, $request) !== self::LEVEL_NONE;
    }

    /**
     * May this user **decide** requests — approve, decline, ask for a
     * face-to-face, close?
     *
     * Administrator-only, and a role check rather than a permission one for the
     * reason the other two visibility services give: a Technician holds the
     * identical `maintenance.*` set, so the permission cannot express the
     * difference. Deciding a colleague's request is oversight, not fieldwork.
     */
    public function canDecide(User $user): bool
    {
        return $this->isAdministrator($user) && $this->hasFloor($user);
    }

    /**
     * May this user withdraw this request (FR-WSR-014)?
     *
     * The submitting technician, **or** an administrator on their behalf — the
     * requirement names both, and `cancelled_by` records which. Bounded to
     * requests with no decision recorded: withdrawing something already approved
     * or declined would erase an administrator's answer.
     */
    public function canCancel(User $user, WorkSupportRequest $request): bool
    {
        if (! $request->status->isUndecided()) {
            return false;
        }

        return $this->isAdministrator($user)
            ? $this->hasFloor($user)
            : $this->canSee($user, $request);
    }

    /**
     * May this user acknowledge the new schedule (FR-WSR-006)?
     *
     * The submitting technician only. An acknowledgement is a statement that
     * *this person* has seen the reschedule; an administrator ticking it for
     * them would record something that did not happen.
     */
    public function canAcknowledge(User $user, WorkSupportRequest $request): bool
    {
        return $request->technician_id === $user->getKey() && $this->hasFloor($user);
    }

    /**
     * The administrative surface: the cross-estate request inbox (FR-WSR-010).
     *
     * Role-gated, because the floor cannot close it — a Technician holds
     * `maintenance.view` too. The `/admin` route prefix is a convention, not the
     * control (the stance `TicketPolicy` and `MaintenanceRecordPolicy` both
     * take).
     */
    public function canSeeAdministrative(User $user): bool
    {
        return $this->canDecide($user);
    }

    private function isAdministrator(User $user): bool
    {
        return $user->role?->slug === 'administrator';
    }
}
