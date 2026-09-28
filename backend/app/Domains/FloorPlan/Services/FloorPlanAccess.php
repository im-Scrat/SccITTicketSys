<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

use App\Models\User;

/**
 * The single answer to "may this user see or change the floor plan?".
 *
 * **The Interactive Floor Plan is Administrator-only.** The client's brief is
 * explicit — "teachers and technicians will not have this access" — and this
 * is the one predicate both floor-plan policies delegate to, so the rule cannot
 * be stated two ways and drift.
 *
 * Both halves are required, deliberately:
 *
 *  - **The role.** A permission alone cannot keep this module closed. Grants
 *    are editable per user at runtime (FR-USER-010), and `Gate::before` in
 *    `AppServiceProvider` allows any ability whose *string* equals a permission
 *    in the user's effective set — so an Administrator granting a Technician
 *    `floorplan.manage` would open every `can:floorplan.manage` gate on the
 *    spot. Requiring the role means that grant changes nothing here.
 *  - **The permission.** An Administrator's own set is still honoured: a
 *    per-user *deny* (deny wins, DD-05) can withdraw floor-plan access from a
 *    named administrator without demoting them.
 *
 * Because of the `Gate::before` behaviour above, floor-plan routes and
 * services must authorize through the **policy abilities** (`viewAny`, `view`,
 * `manage`) and never through a bare `floorplan.*` permission string.
 */
final class FloorPlanAccess
{
    public function canView(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('floorplan.view');
    }

    public function canManage(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('floorplan.manage');
    }
}
