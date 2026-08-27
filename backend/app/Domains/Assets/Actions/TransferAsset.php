<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Services\AssetGuard;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an asset between rooms (SRS FR-AST-006).
 *
 * The move and its receipt are one transaction: `assets.current_room_id` is
 * updated **and** an `asset_transfers` row is written, so the register's current
 * answer and its history can never disagree. That pairing is the whole point of
 * routing moves through this Action rather than letting `UpdateAsset` write the
 * room column directly.
 *
 * A **disposed** asset cannot be moved — it has left the organization, so there
 * is no room to move it to. That is an invariant, not a permission, so it
 * answers 422 (SDD DD-29).
 */
class TransferAsset
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssetGuard $guard,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Asset $asset,
        ?Room $target,
        User $actor,
        Request $request,
        ?string $reason = null,
        ?string $remarks = null,
    ): Asset {
        if (! $this->guard->isMovable($asset)) {
            throw ValidationException::withMessages([
                'room' => 'A disposed asset has left the estate and can no longer be transferred.',
            ]);
        }

        $asset->loadMissing('currentRoom.floor.building');
        $from = $asset->currentRoom;

        if ($from?->getKey() === $target?->getKey()) {
            throw ValidationException::withMessages([
                'room' => 'This asset is already in that room.',
            ]);
        }

        $target?->loadMissing('floor.building');

        DB::transaction(function () use ($asset, $from, $target, $actor, $reason, $remarks): void {
            $asset->forceFill([
                'current_room_id' => $target?->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            AssetTransfer::query()->create([
                'asset_id' => $asset->getKey(),
                'from_room_id' => $from?->getKey(),
                'to_room_id' => $target?->getKey(),
                'transferred_by' => $actor->getKey(),
                'reason' => $reason,
                'remarks' => $remarks,
                'transferred_at' => now(),
            ]);
        });

        $this->audit->activity(
            ActivityAction::AssetTransferred,
            actor: $actor,
            subject: $asset,
            properties: [
                'from' => $this->label($from),
                'to' => $this->label($target),
                'reason' => $reason,
                'remarks' => $remarks,
            ],
            request: $request,
            module: 'assets',
            description: sprintf(
                'Asset %s moved from %s to %s',
                $asset->asset_tag,
                $this->label($from) ?? 'no room',
                $this->label($target) ?? 'no room',
            ),
        );

        return $asset->refresh()->load('currentRoom.floor.building');
    }

    private function label(?Room $room): ?string
    {
        if ($room === null) {
            return null;
        }

        $floor = $room->floor;

        $parts = array_values(array_filter([
            $floor?->building?->name,
            $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
            $room->name,
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
