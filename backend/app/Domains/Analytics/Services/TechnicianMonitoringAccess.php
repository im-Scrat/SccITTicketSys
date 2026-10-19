<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Models\User;

/**
 * Phase 2.9 — who may read the technician monitoring surface
 * (`/admin/technicians/*`): an Administrator who also holds `users.view`.
 *
 * The role is required for the reason FloorPlanAccess documents —
 * `Gate::before` answers a bare permission string for anyone granted it, so a
 * per-user `users.view` grant to a technician must not open colleagues'
 * workload figures. The permission is kept so a per-user *deny* can still
 * withdraw the surface from a named administrator (deny wins, DD-05).
 */
final class TechnicianMonitoringAccess
{
    public function canView(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('users.view');
    }
}
