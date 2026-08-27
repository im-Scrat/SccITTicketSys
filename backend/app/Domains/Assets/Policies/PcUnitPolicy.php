<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Models\PcUnit;
use App\Models\User;

/**
 * Per-record authorization for PC units (SRS FR-PC-001..006).
 *
 * PC units are part of the Asset Management module and share its permission
 * family and its Administrator-only scope (SDD DD-38) — see {@see AssetPolicy}
 * for the full reasoning. A Technician reaches the PC they are repairing through
 * their assigned ticket or maintenance record, via the narrow lookup
 * ({@see selectPcUnit}); a Teacher reaches only the PC they are reporting.
 * Neither can browse the register.
 *
 * The specification snapshot (FR-PC-003) is edited under `assets.update`: it is
 * a property of the machine, not a separate object with its own permission.
 */
class PcUnitPolicy
{
    /**
     * Workflows that legitimately need to name a machine. A Teacher reporting a
     * fault holds `tickets.create`; a Technician recording work holds
     * `maintenance.*`. Neither implies `assets.*`.
     *
     * @var list<string>
     */
    private const PC_CONSUMERS = [
        'tickets.view',
        'tickets.create',
        'tickets.update',
        'maintenance.view',
        'maintenance.create',
        'maintenance.update',
        'maintenance.complete',
        'floorplan.view',
    ];

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('assets.view');
    }

    public function view(User $actor, PcUnit $pcUnit): bool
    {
        return $this->viewAny($actor);
    }

    public function viewHistory(User $actor, PcUnit $pcUnit): bool
    {
        return $this->viewAny($actor);
    }

    public function viewAudit(User $actor, PcUnit $pcUnit): bool
    {
        return $this->viewAny($actor);
    }

    /**
     * May this user resolve a PC through the narrow lookup (not the module)?
     */
    public function selectPcUnit(User $actor): bool
    {
        if ($actor->hasPermissionTo('assets.view')) {
            return true;
        }

        foreach (self::PC_CONSUMERS as $permission) {
            if ($actor->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('assets.create');
    }

    public function update(User $actor, PcUnit $pcUnit): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    /** Edit the 1:1 specification snapshot (FR-PC-003). */
    public function updateSpecification(User $actor, PcUnit $pcUnit): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    public function manageAttachments(User $actor, PcUnit $pcUnit): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    public function manageQr(User $actor, PcUnit $pcUnit): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    /** Archive (soft delete). The in-use state is checked by the Action (422). */
    public function delete(User $actor, PcUnit $pcUnit): bool
    {
        return $actor->hasPermissionTo('assets.delete');
    }

    public function restore(User $actor, PcUnit $pcUnit): bool
    {
        return $actor->hasPermissionTo('assets.delete');
    }
}
