<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Locations\Services\LocationGuard;
use App\Enums\AssetStatus;
use App\Enums\InstallationStatus;
use App\Models\Asset;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asset-safety invariants shared by the Policies (authorization) and the Actions
 * (defense in depth) — the {@see LocationGuard} analogue for Phase 2.5.
 *
 * The rules exist so that archiving or moving a record can never silently strand
 * something that still depends on it:
 *
 *  - **installed**   : an asset currently installed inside a PC may not be
 *                      archived — removing it from the estate would leave the PC
 *                      claiming a part that no longer exists (FR-PC-004).
 *  - **open_tickets**: a target with open tickets may not be archived; the work
 *                      has to be resolved or closed first. Ticket history is
 *                      never rewritten (the same stance FR-LOC-009 takes). For
 *                      an asset that means its own tickets and its host PC's.
 *  - **components**  : a PC unit still holding installed components may not be
 *                      archived until they are removed or the PC is stripped.
 *
 * As in Phase 2.4 (SDD DD-29) these answer **422 with a blocker report** from the
 * Action, never 403 from the Policy: "you may not archive assets" and "this asset
 * is still inside a PC" are different answers and must not collapse into one
 * status code.
 *
 * "Live" deliberately excludes history: a soft-deleted PC, a removed
 * installation or a closed ticket never blocks anything.
 */
class AssetGuard
{
    /**
     * Blocker counts for archiving one asset.
     *
     * @return array{installed: int, open_tickets: int}
     */
    public function assetBlockers(Asset $asset): array
    {
        return [
            'installed' => $asset->installations()
                ->where('installation_status', InstallationStatus::Installed->value)
                ->whereIn('pc_unit_id', PcUnit::query()->select('id'))
                ->count(),
            'open_tickets' => $this->openTicketsForAsset($asset),
        ];
    }

    /**
     * Blocker counts for archiving one PC unit.
     *
     * @return array{components: int, open_tickets: int}
     */
    public function pcUnitBlockers(PcUnit $pcUnit): array
    {
        return [
            'components' => $pcUnit->componentInstallations()
                ->where('installation_status', InstallationStatus::Installed->value)
                ->count(),
            'open_tickets' => $pcUnit->tickets()
                ->whereHas('status', fn (Builder $q): Builder => $q->where('is_open', true))
                ->count(),
        ];
    }

    public function assetIsInUse(Asset $asset): bool
    {
        return array_sum($this->assetBlockers($asset)) > 0;
    }

    public function pcUnitIsInUse(PcUnit $pcUnit): bool
    {
        return array_sum($this->pcUnitBlockers($pcUnit)) > 0;
    }

    /**
     * A disposed asset has left the organization: it can no longer be moved
     * between rooms or handed to a custodian. Retired assets *can* still be
     * moved — they are usually being consolidated into a store room.
     */
    public function isMovable(Asset $asset): bool
    {
        return $asset->status !== AssetStatus::Disposed;
    }

    /**
     * Which PC, if any, currently holds this asset — so the 422 can name it
     * rather than just refusing.
     */
    public function installedIn(Asset $asset): ?PcUnit
    {
        $installation = $asset->installations()
            ->where('installation_status', InstallationStatus::Installed->value)
            ->latest('installation_date')
            ->first();

        return $installation?->pcUnit;
    }

    /**
     * Open tickets this asset is exposed to: those reported against the asset
     * itself (`tickets.asset_id`, Phase 2.9 SDD DD-79 — a printer, a projector),
     * plus those against the PC it is fitted in, if any — a fault on the PC may
     * well be this part's.
     *
     * The two sets cannot overlap: `tickets_equipment_target_check` refuses a
     * ticket naming both a PC and an asset, so the sum never double-counts.
     */
    private function openTicketsForAsset(Asset $asset): int
    {
        $open = fn (Builder $q): Builder => $q->where('is_open', true);

        $own = $asset->tickets()->whereHas('status', $open)->count();

        $pcUnit = $this->installedIn($asset);

        if ($pcUnit === null) {
            return $own;
        }

        return $own + $pcUnit->tickets()->whereHas('status', $open)->count();
    }
}
