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
 * Bring an archived asset back into the register (SRS FR-AST-012).
 *
 * Restoring is a plain `restore()` — unlike a location, an asset has no subtree
 * to reverse, so the cascade-stamp machinery of SDD DD-27 has no counterpart
 * here. Its history was never touched by the archive, so it simply becomes
 * visible again.
 *
 * The asset returns with the lifecycle status it held when archived, which is
 * why `AssetStatus` has no `Archived` case: archiving is orthogonal to the
 * lifecycle, not a point on it.
 */
class RestoreAsset
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Asset $asset, User $actor, Request $request): Asset
    {
        DB::transaction(function () use ($asset, $actor): void {
            $asset->restore();
            $asset->forceFill(['updated_by' => $actor->getKey()])->save();
        });

        $this->audit->activity(
            ActivityAction::AssetRestored,
            actor: $actor,
            subject: $asset,
            properties: [
                'asset_tag' => $asset->asset_tag,
                'name' => $asset->displayName(),
                'status' => $asset->status->value,
                'status_label' => $asset->status->label(),
            ],
            request: $request,
            module: 'assets',
            description: "Asset {$asset->asset_tag} restored",
        );

        return $asset->refresh()->load([
            'hardwareModel.component.manufacturer',
            'supplier',
            'currentRoom.floor.building',
            'assignedTechnician',
        ]);
    }
}
