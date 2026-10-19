<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\FloorPlan\Services\FloorPlanAccess;
use App\Models\User;

/**
 * The single answer to "may this user see or decide predictive-maintenance
 * findings?" (WP-M) — **Administrator-only**, per the mandate.
 *
 * Both halves are required, deliberately — the same reasoning
 * {@see FloorPlanAccess} states in full:
 *
 *  - **The role.** `Gate::before` in `AppServiceProvider` passes any ability
 *    whose *string* equals a permission in the user's effective set. A
 *    per-user grant of `predictions.view` (FR-USER-010 allows one to anybody)
 *    would open a bare `can:predictions.view` gate on the spot if one existed
 *    anywhere in this codebase. Requiring the role here means it never can:
 *    every route and controller check in this module goes through the policy
 *    abilities (`viewAny`/`view`/`manage`), never the permission string.
 *  - **The permission.** An Administrator's own set is still honoured — a
 *    per-user deny (deny wins, DD-05) can withdraw this surface from a named
 *    administrator without demoting them.
 */
final class PcPredictionAccess
{
    public function canView(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('predictions.view');
    }

    public function canManage(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('predictions.manage');
    }
}
