<?php

declare(strict_types=1);

namespace App\Domains\Locations\Policies;

use App\Models\Floor;
use App\Models\User;

/**
 * Per-record authorization for floors (SRS FR-LOC-002/004). Administrator-only,
 * like the rest of the module. Permission questions only — the in-use invariant
 * answers with a 422 from the Action, as in {@see BuildingPolicy}.
 *
 * Floors carry no `is_active` flag — their availability derives from the parent
 * building — so there is no `activate` ability here.
 */
class FloorPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('locations.view');
    }

    public function view(User $actor, Floor $floor): bool
    {
        return $this->viewAny($actor);
    }

    public function viewAudit(User $actor, Floor $floor): bool
    {
        return $actor->hasPermissionTo('locations.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('locations.create');
    }

    public function update(User $actor, Floor $floor): bool
    {
        return $actor->hasPermissionTo('locations.update');
    }

    /** Archive (soft delete). The in-use state is checked by the Action (422). */
    public function delete(User $actor, Floor $floor): bool
    {
        return $actor->hasPermissionTo('locations.delete');
    }

    public function restore(User $actor, Floor $floor): bool
    {
        return $actor->hasPermissionTo('locations.delete');
    }
}
