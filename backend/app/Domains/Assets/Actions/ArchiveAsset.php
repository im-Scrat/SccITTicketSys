<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Exceptions\AssetInUseException;
use App\Domains\Assets\Services\AssetGuard;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Archive (soft delete) an asset (SRS FR-AST-012, BR-12 — business entities are
 * never hard-deleted).
 *
 * The guard is consulted first: an asset still installed inside a PC, or with
 * open tickets against its host, is refused with a **422 blocker report** naming
 * what is in the way. This repeats the Policy's check on purpose — authorization
 * and the write path each enforce their own invariant (defense in depth, as in
 * Phases 2.3 and 2.4).
 *
 * Archiving preserves everything: `asset_status_history`, `asset_transfers`,
 * installations and QR scan logs all keep their foreign keys, because a soft
 * delete is an `UPDATE`. Restoring is therefore lossless (FR-AST-005 — history
 * is never lost).
 */
class ArchiveAsset
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssetGuard $guard,
    ) {}

    /**
     * @throws AssetInUseException
     */
    public function handle(Asset $asset, User $actor, Request $request): Asset
    {
        $blockers = $this->guard->assetBlockers($asset);

        if (array_sum($blockers) > 0) {
            $host = $this->guard->installedIn($asset);

            throw new AssetInUseException('asset', $blockers, array_filter([
                'installed_in' => $host !== null
                    ? ['id' => $host->uuid, 'name' => $host->pc_name, 'unit_code' => $host->unit_code]
                    : null,
            ]));
        }

        DB::transaction(function () use ($asset, $actor): void {
            $asset->forceFill(['updated_by' => $actor->getKey()])->save();
            $asset->delete();
        });

        $this->audit->activity(
            ActivityAction::AssetArchived,
            actor: $actor,
            subject: $asset,
            properties: [
                'asset_tag' => $asset->asset_tag,
                'name' => $asset->displayName(),
                'status' => $asset->status->value,
            ],
            request: $request,
            module: 'assets',
            description: "Asset {$asset->asset_tag} archived",
        );

        return $asset;
    }
}
