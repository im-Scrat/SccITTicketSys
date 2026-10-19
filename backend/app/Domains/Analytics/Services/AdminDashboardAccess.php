<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Models\User;

/**
 * Phase 2.9 — who may subscribe to the live administrator dashboard channel
 * (`admin.dashboard`, routes/channels.php).
 *
 * The **role**, not a permission string, for the reason FloorPlanAccess
 * documents: `Gate::before` answers any ability whose name equals a permission
 * the user holds, and permissions are per-user grantable, so a string check
 * could be opened for a teacher by a single grant. There is no dashboard
 * permission to check alongside it — the dashboard endpoint itself is open to
 * every role and decides its *content* by role.
 *
 * What the channel carries is only "something changed, refetch" plus a uuid;
 * every figure still arrives through the role-gated HTTP endpoints.
 */
final class AdminDashboardAccess
{
    public function canView(User $user): bool
    {
        return $user->isAdministrator();
    }
}
