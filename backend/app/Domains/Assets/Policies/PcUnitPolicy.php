<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Domains\Assets\Services\ScannedPcAccess;
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

    /**
     * The **scan-scoped panel** for one machine (SRS FR-QR-012; SDD DD-49).
     *
     * A third door onto a PC unit, between the label-only lookup
     * ({@see selectPcUnit}) and the Administrator's record ({@see view}), and
     * the only one a Technician can open. It is authorized by its **own**
     * ability rather than by any `assets.*` permission, because a Technician
     * holds none (DD-38) and FR-AST-013 is explicit that a workflow needing to
     * reach equipment is authorized by the permission of *that workflow* —
     * here `maintenance.*` / `tickets.update`, checked inside
     * {@see ScannedPcAccess::hasFloor()}.
     *
     * **Scanning grants nothing.** No code reaches this method, and none is
     * consulted by it: the answer is the same whether the caller scanned the
     * sticker, typed the URL or guessed it. What decides is whether the machine
     * is reachable through work this person actually holds — the identical
     * predicate the panel query uses, so a machine outside their reachable set
     * is equally unreachable by uuid (FR-QR-012, NFR-SEC-003).
     */
    public function viewScanned(User $actor, PcUnit $pcUnit): bool
    {
        return app(ScannedPcAccess::class)->canReach($actor, $pcUnit);
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
