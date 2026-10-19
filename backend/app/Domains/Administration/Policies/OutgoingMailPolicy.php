<?php

declare(strict_types=1);

namespace App\Domains\Administration\Policies;

use App\Models\User;

/**
 * Who may inspect and test outgoing mail: an Administrator holding
 * `system.settings.manage` — the role *and* the permission, for the reason
 * AiAdministrationAccess and ReportsAccess give: a per-user grant of the
 * permission string must not open system configuration to a Technician.
 *
 * Even then the surface is narrow by construction: it shows configuration
 * without credentials, opens a connection without sending, and sends its one
 * fixed message only to the requesting administrator's own address.
 */
class OutgoingMailPolicy
{
    public function manage(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('system.settings.manage');
    }
}
