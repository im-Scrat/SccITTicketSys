<?php

declare(strict_types=1);

namespace App\Domains\Locations\Policies;

use App\Domains\Locations\Http\Resources\LocationOptionResource;
use App\Models\Room;
use App\Models\User;

/**
 * Per-record authorization for rooms (SRS FR-LOC-003/004/005/011).
 *
 * **The Locations module is Administrator-only.** Every ability here requires a
 * `locations.*` permission, which only Administrators hold — so the directory,
 * the tree, the detail pages and every write are closed to Technicians and
 * Teachers at the server, not merely hidden in the UI.
 *
 * {@see selectLocation} is the one deliberate exception, and it is not part of
 * the module: it authorizes the **narrow room lookup** that a non-admin form uses
 * to name a place (FR-LOC-011). It is granted by the permission of the workflow
 * that needs the field — reporting a ticket, recording maintenance, moving an
 * asset — rather than by a locations permission, so the lookup can never become a
 * back door into site administration. The response is label-only
 * ({@see LocationOptionResource}).
 *
 * Permission questions only — the in-use invariant answers with a 422 from the
 * Action, as in {@see BuildingPolicy}.
 */
class RoomPolicy
{
    /**
     * Workflows that legitimately need to name a location. Holding any one of
     * these is what opens the lookup; none of them grants module access.
     *
     * @var list<string>
     */
    private const LOCATION_CONSUMERS = [
        'tickets.view',
        'tickets.create',
        'tickets.update',
        'assets.view',
        'assets.update',
        'assets.transfer',
        'maintenance.view',
        'maintenance.create',
        'maintenance.update',
        'inventory.view',
        'inventory.adjust',
        'floorplan.view',
    ];

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('locations.view');
    }

    public function view(User $actor, Room $room): bool
    {
        return $this->viewAny($actor);
    }

    public function viewAudit(User $actor, Room $room): bool
    {
        return $actor->hasPermissionTo('locations.view');
    }

    /**
     * May this user resolve locations through the narrow lookup (not the module)?
     */
    public function selectLocation(User $actor): bool
    {
        if ($actor->hasPermissionTo('locations.view')) {
            return true;
        }

        foreach (self::LOCATION_CONSUMERS as $permission) {
            if ($actor->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('locations.create');
    }

    public function update(User $actor, Room $room): bool
    {
        return $actor->hasPermissionTo('locations.update');
    }

    /** Activate / deactivate — reversible, so no in-use check applies. */
    public function activate(User $actor, Room $room): bool
    {
        return $actor->hasPermissionTo('locations.update');
    }

    /** Archive (soft delete). The in-use state is checked by the Action (422). */
    public function delete(User $actor, Room $room): bool
    {
        return $actor->hasPermissionTo('locations.delete');
    }

    public function restore(User $actor, Room $room): bool
    {
        return $actor->hasPermissionTo('locations.delete');
    }
}
