<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hand an asset to a custodian, or take it back (SRS FR-AST-002).
 *
 * Assignment and un-assignment are the same operation with a null target, but
 * they are audited as **different actions** so the timeline reads honestly:
 * "Technician assigned — Rivera" and "Technician unassigned" are different
 * events, and collapsing them into one "updated" row would lose that.
 *
 * Custodianship does not change the asset's lifecycle status. Handing a laptop
 * to a technician for safekeeping is not the same as putting it into repair, and
 * conflating them would corrupt `asset_status_history`.
 */
class AssignAssetTechnician
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Asset $asset, ?User $technician, User $actor, Request $request): Asset
    {
        $asset->loadMissing('assignedTechnician');
        $previous = $asset->assignedTechnician;

        if ($previous?->getKey() === $technician?->getKey()) {
            return $asset;
        }

        DB::transaction(function () use ($asset, $technician, $actor): void {
            $asset->forceFill([
                'assigned_technician_id' => $technician?->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();
        });

        $assigning = $technician !== null;

        $this->audit->activity(
            $assigning ? ActivityAction::AssetTechnicianAssigned : ActivityAction::AssetTechnicianUnassigned,
            actor: $actor,
            subject: $asset,
            properties: [
                'from' => $previous?->fullName(),
                'from_id' => $previous?->uuid,
                'to' => $technician?->fullName(),
                'to_id' => $technician?->uuid,
            ],
            request: $request,
            module: 'assets',
            description: $assigning
                ? "Asset {$asset->asset_tag} assigned to {$technician->fullName()}"
                : "Asset {$asset->asset_tag} unassigned".($previous !== null ? " from {$previous->fullName()}" : ''),
        );

        return $asset->refresh()->load('assignedTechnician');
    }
}
