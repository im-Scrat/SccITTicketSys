<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Services;

use App\Domains\FloorPlan\Exceptions\FloorPlanRuleViolation;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * How a room's layouts are found, and which PC units and layouts belong
 * together (SDD §25).
 *
 * **Every read authorizes first.** A controller that forgets `authorize()`
 * still cannot leak a layout, because the lookup itself refuses a
 * non-administrator before it touches the database. It also means the answer to
 * an unauthorized caller is the same whether or not the uuid exists — a 403
 * either way, never a 404 that confirms a room is real.
 *
 * **Addressing.** Rooms and PC units are addressed by `uuid`. A layout has no
 * uuid of its own and needs none: it is identified as *(room uuid, version)*,
 * which `room_layouts_room_id_version_unique` makes unambiguous. Resolving a
 * layout *through its room* makes a mismatched room/layout pair unrepresentable
 * — a version that belongs to another room simply is not found — rather than
 * something to remember to check afterwards.
 *
 * Creating and activating versions is not here: that is layout persistence, a
 * later package. The one-active-per-room rule is already enforced by the
 * database (`room_layouts_one_active_per_room`); {@see assertEditable()} is the
 * application-side reading of it.
 */
class RoomLayoutService
{
    /** Resolve a room by its public uuid. */
    public function room(User $actor, string $uuid): Room
    {
        $this->authorizeView($actor);

        // A malformed value would otherwise reach Postgres as an invalid uuid
        // literal and surface as a 500 instead of a 404.
        if (! Str::isUuid($uuid)) {
            throw (new ModelNotFoundException)->setModel(Room::class);
        }

        return Room::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** The room's active layout, or null when it has none yet. */
    public function activeLayout(User $actor, Room $room): ?RoomLayout
    {
        $this->authorizeView($actor);
        $this->requireLiveRoom($room);

        return $room->layouts()->where('is_active', true)->first();
    }

    /**
     * One specific version of a room's layout, whether active or historical.
     *
     * @throws ModelNotFoundException when the room has no such version
     */
    public function layout(User $actor, Room $room, int $version): RoomLayout
    {
        $this->authorizeView($actor);
        $this->requireLiveRoom($room);

        $layout = $room->layouts()->where('version', $version)->firstOrFail();

        Gate::forUser($actor)->authorize('view', $layout);

        return $layout;
    }

    /**
     * A PC unit of this layout's room, by uuid.
     *
     * Scoped to the room in the query, so a PC in another room is *not found*
     * rather than found-and-refused: the response gives no confirmation that
     * the uuid exists elsewhere.
     */
    public function pcUnit(User $actor, RoomLayout $layout, string $uuid): PcUnit
    {
        $this->authorizeView($actor);

        if (! Str::isUuid($uuid)) {
            throw (new ModelNotFoundException)->setModel(PcUnit::class);
        }

        return PcUnit::query()
            ->where('room_id', $layout->room_id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * For a PC unit that arrived already resolved (a route-bound `{pcUnit:uuid}`
     * resolves any PC in the estate): it must be homed in the layout's room.
     *
     * Not an authorization check — callers authorize before reaching it. It
     * exists because placing a PC on another room's plan would re-home it,
     * which is an asset transfer and is governed by `assets.transfer`.
     *
     * @throws FloorPlanRuleViolation
     */
    public function assertPcUnitInRoom(RoomLayout $layout, PcUnit $pcUnit): void
    {
        if ($pcUnit->room_id === null || $pcUnit->room_id !== $layout->room_id) {
            throw FloorPlanRuleViolation::pcUnitNotInRoom();
        }
    }

    /**
     * Only the active layout may be changed; every other version is history.
     *
     * @throws FloorPlanRuleViolation
     */
    public function assertEditable(RoomLayout $layout): void
    {
        if (! $layout->is_active) {
            throw FloorPlanRuleViolation::layoutNotActive();
        }
    }

    private function authorizeView(User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', RoomLayout::class);
    }

    /** An archived room is gone as far as the floor plan is concerned. */
    private function requireLiveRoom(Room $room): void
    {
        if ($room->trashed()) {
            throw (new ModelNotFoundException)->setModel(Room::class, [$room->getKey()]);
        }
    }
}
