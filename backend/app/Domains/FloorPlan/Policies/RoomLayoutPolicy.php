<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Policies;

use App\Domains\FloorPlan\Services\FloorPlanAccess;
use App\Models\RoomLayout;
use App\Models\User;

/**
 * Authorization for room layouts (SRS FR-FP-001/006/008).
 *
 * Administrator-only — see {@see FloorPlanAccess} for why the role is checked
 * as well as the permission. Every ability delegates there, so this policy and
 * {@see FloorPlanPositionPolicy} can never disagree.
 *
 * The layout instance is accepted but not consulted: the floor plan has no
 * row-level scoping to express (every Administrator may open every room's
 * plan), so "absent from a user's list" and "unreachable by uuid" are the same
 * fact by construction — a non-administrator holds neither.
 */
class RoomLayoutPolicy
{
    public function __construct(private readonly FloorPlanAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canView($actor);
    }

    public function view(User $actor, RoomLayout $layout): bool
    {
        return $this->access->canView($actor);
    }

    /**
     * Any change to a layout — create, edit, activate. The optional instance
     * lets `Gate::authorize('manage', RoomLayout::class)` (nothing to load yet)
     * and `Gate::authorize('manage', $layout)` share one method.
     */
    public function manage(User $actor, ?RoomLayout $layout = null): bool
    {
        return $this->access->canManage($actor);
    }
}
