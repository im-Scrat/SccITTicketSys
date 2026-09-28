<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Policies;

use App\Domains\FloorPlan\Services\FloorPlanAccess;
use App\Models\FloorPlanPosition;
use App\Models\User;

/**
 * Authorization for PC-unit positions on a layout (SRS FR-FP-003/006).
 *
 * Same rule and same predicate as {@see RoomLayoutPolicy}: Administrator-only,
 * read and write separately. A position is only ever reached through its
 * layout, so no rule here can be looser than the layout's.
 */
class FloorPlanPositionPolicy
{
    public function __construct(private readonly FloorPlanAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canView($actor);
    }

    public function view(User $actor, FloorPlanPosition $position): bool
    {
        return $this->access->canView($actor);
    }

    /** Place, move or clear a PC unit. Accepts a class name or an instance. */
    public function manage(User $actor, ?FloorPlanPosition $position = null): bool
    {
        return $this->access->canManage($actor);
    }
}
