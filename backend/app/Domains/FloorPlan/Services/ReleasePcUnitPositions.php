<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

use App\Domains\FloorPlan\Events\RoomPlanChanged;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\FloorPlanPosition;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * What happens to a machine's place on a Floorplan when the machine leaves
 * the room (Phase 2.11, SDD DD-104).
 *
 * Before this, nothing cleaned up: a machine moved to another room — or
 * archived — kept its position row, and the map simply stopped drawing it
 * (`FloorPlanService` filters on the machine's current room). That made a
 * machine's return awkward: it reappeared on its old spot, which another
 * machine might have taken since.
 *
 * Now the position on the old room's **active** layout is deleted, inside the
 * caller's transaction, with an `AssetPositionCleared` audit row on the
 * machine (so FR-FP-008's "moved rooms without data loss" holds — the history
 * is the audit trail), and the layout's revision is bumped so an editor
 * holding a draft that still places the machine learns that its draft is
 * stale. Positions on inactive versions are history and are left alone.
 *
 * The old room's active layout row is locked *after* the caller has written
 * the machine — the order `SaveRoomLayout` never inverts (it locks only the
 * layout, and reads machines without locking them), so the two cannot
 * deadlock.
 *
 * Both rooms are told (`RoomPlanChanged`, cause `roster`) after commit: the
 * old room lost a machine, the new room gained one for its unplaced list.
 *
 * Callers: `UpdatePcUnit` (room change), `ReassignRoomOccupants` (bulk move)
 * and `ArchivePcUnit`; `RestorePcUnit` only announces the arrival.
 */
final class ReleasePcUnitPositions
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  'room_change'|'archived'  $reason
     */
    public function release(PcUnit $pcUnit, ?int $fromRoomId, string $reason, ?User $actor, ?Request $request = null): void
    {
        if ($fromRoomId === null) {
            return;
        }

        $this->releaseMany([$pcUnit->getKey()], $fromRoomId, $reason, $actor, $request);
    }

    /**
     * Release several machines leaving one room together (the bulk room
     * reassignment).
     *
     * @param  list<int>  $pcUnitIds
     * @param  'room_change'|'archived'  $reason
     */
    public function releaseMany(array $pcUnitIds, int $fromRoomId, string $reason, ?User $actor, ?Request $request = null): void
    {
        $room = Room::withTrashed()->find($fromRoomId);

        if ($room === null || $pcUnitIds === []) {
            return;
        }

        /** @var RoomLayout|null $layout */
        $layout = RoomLayout::query()
            ->where('room_id', $fromRoomId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        $released = $layout === null
            ? collect()
            : FloorPlanPosition::query()
                ->where('room_layout_id', $layout->getKey())
                ->whereIn('pc_unit_id', $pcUnitIds)
                ->with(['pcUnit' => fn ($q) => $q->withTrashed()])
                ->get();

        if ($layout !== null && $released->isNotEmpty()) {
            FloorPlanPosition::query()->whereKey($released->pluck('id')->all())->delete();

            $layout->forceFill([
                'revision' => $layout->revision + 1,
                'updated_by' => $actor?->getKey() ?? $layout->updated_by,
            ])->save();

            foreach ($released as $position) {
                $this->audit->activity(
                    ActivityAction::AssetPositionCleared,
                    actor: $actor,
                    subject: $position->pcUnit,
                    properties: [
                        'from' => [
                            'x' => (float) $position->pos_x,
                            'y' => (float) $position->pos_y,
                            'rotation' => (int) round((float) $position->rotation),
                        ],
                        'layout_version' => $layout->version,
                        'room' => $room->name,
                        'reason' => $reason,
                    ],
                    request: $request,
                    module: 'floor-plan',
                    description: "{$position->pcUnit?->pc_name} left the Floorplan of {$room->name}",
                );
            }
        }

        RoomPlanChanged::dispatch(
            $room->uuid,
            $layout?->version,
            $released->isNotEmpty() ? $layout?->revision : null,
            'roster',
            $actor?->fullName(),
        );
    }

    /** A machine arrived in (or returned to) a room: its unplaced list moved. */
    public function announceArrival(?Room $room, ?User $actor): void
    {
        if ($room === null) {
            return;
        }

        RoomPlanChanged::dispatch($room->uuid, null, null, 'roster', $actor?->fullName());
    }
}
