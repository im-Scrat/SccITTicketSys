<?php

declare(strict_types=1);

namespace App\Domains\Assets\Policies;

use App\Domains\Assets\Http\Resources\AssetOptionResource;
use App\Domains\Locations\Policies\RoomPolicy;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\User;

/**
 * Per-record authorization for serialized assets (SRS FR-AST-002..012).
 *
 * **The Asset Management module is Administrator-only** (SDD DD-38), on exactly
 * the reasoning that closed Locations in Phase 2.4: managing the estate's
 * equipment register is site administration, not day-to-day work. Every ability
 * here requires an `assets.*` permission, and those are seeded to Administrators
 * alone — so the dashboard, the directory, the detail pages and every write are
 * closed to Technicians and Teachers **at the server**, not merely hidden in the
 * client.
 *
 * {@see selectAsset} is the one deliberate exception, and it is not part of the
 * module: it authorizes the **narrow asset lookup** a non-admin workflow uses to
 * name a piece of equipment — a technician recording which machine they
 * repaired, a teacher reporting which PC is broken. It is granted by the
 * permission of the workflow that needs it (`tickets.create`,
 * `maintenance.view`, …) rather than by an `assets.*` permission, so the lookup
 * can never become a back door into the register. The response is label-only
 * ({@see AssetOptionResource}). This mirrors {@see RoomPolicy::selectLocation()}
 * exactly, so the product has one rule rather than two.
 *
 * Permission questions only. The in-use invariant (an asset installed in a PC,
 * or with open tickets) answers with a **422 from the Action** — SDD DD-29's
 * split, preserved here.
 */
class AssetPolicy
{
    /**
     * Workflows that legitimately need to name a piece of equipment. Holding any
     * one of these opens the lookup; none of them grants module access.
     *
     * @var list<string>
     */
    private const ASSET_CONSUMERS = [
        'tickets.view',
        'tickets.create',
        'tickets.update',
        'maintenance.view',
        'maintenance.create',
        'maintenance.update',
        'maintenance.complete',
    ];

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('assets.view');
    }

    /**
     * The module gate (`assets.view`) opens every record. The one exception is
     * the custodian themselves: whoever an asset is currently handed to
     * (`assigned_technician_id`) can always read that one record, even holding
     * no `assets.*` permission at all — they need to know what they are
     * responsible for. This does not reach the module: {@see AssetController::mine()}
     * is the only route that lets a non-administrator arrive here, and it is
     * pre-scoped to the caller's own custody, so this check never has to widen
     * into "any asset" for them.
     */
    public function view(User $actor, Asset $asset): bool
    {
        return $this->viewAny($actor) || $asset->assigned_technician_id === $actor->getKey();
    }

    /** The unified asset timeline (FR-AST-005). */
    public function viewHistory(User $actor, Asset $asset): bool
    {
        return $this->viewAny($actor);
    }

    public function viewAudit(User $actor, Asset $asset): bool
    {
        return $this->viewAny($actor);
    }

    /**
     * May this user resolve equipment through the narrow lookup (not the module)?
     */
    public function selectAsset(User $actor): bool
    {
        if ($actor->hasPermissionTo('assets.view')) {
            return true;
        }

        foreach (self::ASSET_CONSUMERS as $permission) {
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

    public function update(User $actor, Asset $asset): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    /**
     * Changing lifecycle status.
     *
     * Entering a **terminal** state — retired or disposed — is a materially
     * different act from moving an asset through its working life: it writes the
     * equipment off. So it is gated on `assets.dispose` rather than
     * `assets.update` (SDD DD-33), which lets an Administrator delegate
     * day-to-day status upkeep without also delegating write-off authority.
     *
     * Whether the transition is *legal* is a separate question, answered by
     * AssetLifecycle with a 422.
     */
    public function changeStatus(User $actor, Asset $asset, ?AssetStatus $target = null): bool
    {
        if ($target !== null && $target->isTerminal()) {
            return $actor->hasPermissionTo('assets.dispose');
        }

        return $actor->hasPermissionTo('assets.update');
    }

    /** Move an asset between rooms (FR-AST-006). */
    public function transfer(User $actor, Asset $asset): bool
    {
        return $actor->hasPermissionTo('assets.transfer');
    }

    /** Hand an asset to a custodian, or take it back. */
    public function assignTechnician(User $actor, Asset $asset): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    /** Upload or remove images and documents. */
    public function manageAttachments(User $actor, Asset $asset): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    /** Generate, regenerate, print or revoke a QR label (FR-QR-007). */
    public function manageQr(User $actor, Asset $asset): bool
    {
        return $actor->hasPermissionTo('assets.update');
    }

    /** Archive (soft delete). The in-use state is checked by the Action (422). */
    public function delete(User $actor, Asset $asset): bool
    {
        return $actor->hasPermissionTo('assets.delete');
    }

    public function restore(User $actor, Asset $asset): bool
    {
        return $actor->hasPermissionTo('assets.delete');
    }
}
