<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A custodian confirms that assets assigned to them are physically in their
 * rooms (owner request 2026-10-20, SDD DD-115).
 *
 * A confirmed asset appears on its room's Floorplan, in the "Not placed" tray,
 * for an administrator to arrange. The confirmation is a statement about *this*
 * room: moving the asset elsewhere (`TransferAsset`) clears it, so the new room
 * only shows the asset once someone has seen it there.
 *
 * The caller has already scoped the assets to the actor's own custody (the
 * controller resolves them inside `assigned_technician_id = actor`), so this
 * class only decides what can be confirmed:
 *
 *  - an asset with no room cannot be — there is nowhere to have put it;
 *  - an asset already confirmed is left alone, so "Confirm all" pressed twice
 *    writes nothing the second time and the original confirmer is kept.
 *
 * One audit row per asset confirmed, on the asset itself, so it reads in that
 * asset's own history.
 */
final class ConfirmAssetPlacement
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  Collection<int, Asset>  $assets  already scoped to the actor's custody
     * @return Collection<int, Asset> the assets this call confirmed
     */
    public function handle(Collection $assets, User $actor, Request $request): Collection
    {
        $confirmable = $assets->filter(
            static fn (Asset $asset): bool => $asset->current_room_id !== null && $asset->placement_confirmed_at === null,
        )->values();

        if ($confirmable->isEmpty()) {
            return $confirmable;
        }

        DB::transaction(function () use ($confirmable, $actor): void {
            $now = now();

            foreach ($confirmable as $asset) {
                $asset->forceFill([
                    'placement_confirmed_at' => $now,
                    'placement_confirmed_by' => $actor->getKey(),
                ])->save();
            }
        });

        foreach ($confirmable as $asset) {
            $asset->loadMissing('currentRoom');

            $this->audit->activity(
                ActivityAction::AssetPlacementConfirmed,
                actor: $actor,
                subject: $asset,
                properties: ['room' => $asset->currentRoom?->name],
                request: $request,
                module: 'assets',
                description: "{$asset->displayName()} confirmed in {$asset->currentRoom?->name}",
            );
        }

        return $confirmable;
    }
}
