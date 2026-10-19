<?php

declare(strict_types=1);

namespace App\Domains\Locations\Policies;

use App\Domains\Locations\Actions\ArchiveLocation;
use App\Domains\Locations\Exceptions\LocationInUseException;
use App\Models\Building;
use App\Models\User;

/**
 * Per-record authorization for buildings (SRS FR-LOC-*; SDD §11.1).
 *
 * Route-level `can:locations.*` middleware is the coarse gate; these methods are
 * the per-record layer. They answer **permission** questions only.
 *
 * Every ability requires a `locations.*` permission, which only Administrators
 * hold — the Locations module is site administration, closed to Technicians and
 * Teachers at the server. The narrow room lookup non-admin forms use is a
 * separate surface ({@see RoomPolicy::selectLocation}), not part of this module.
 *
 * The FR-LOC-004 in-use invariant is deliberately *not* enforced here: "you may
 * not archive locations" and "this building still holds live equipment" are
 * different answers and must not collapse into one 403. The invariant lives in
 * {@see ArchiveLocation}, which raises
 * {@see LocationInUseException} — a 422 with the
 * blocker report the UI needs to offer reassignment. The same guard is exposed
 * read-only on the detail endpoints (`meta.in_use`) so the client can warn before
 * the attempt.
 *
 * The permission-slug `Gate::before` does not short-circuit these abilities
 * because the ability names here are not permission slugs, so deny-by-default
 * still falls through to this policy.
 */
class BuildingPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('locations.view');
    }

    public function view(User $actor, Building $building): bool
    {
        return $this->viewAny($actor);
    }

    public function viewAudit(User $actor, Building $building): bool
    {
        return $actor->hasPermissionTo('locations.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('locations.create');
    }

    public function update(User $actor, Building $building): bool
    {
        return $actor->hasPermissionTo('locations.update');
    }

    /** Activate / deactivate — reversible, so no in-use check applies. */
    public function activate(User $actor, Building $building): bool
    {
        return $actor->hasPermissionTo('locations.update');
    }

    /** Archive (soft delete). The in-use state is checked by the Action (422). */
    public function delete(User $actor, Building $building): bool
    {
        return $actor->hasPermissionTo('locations.delete');
    }

    public function restore(User $actor, Building $building): bool
    {
        return $actor->hasPermissionTo('locations.delete');
    }
}
