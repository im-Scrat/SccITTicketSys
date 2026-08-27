<?php

declare(strict_types=1);

namespace App\Domains\Locations\Services;

use App\Domains\Locations\Actions\ReassignRoomOccupants;
use App\Enums\AssetStatus;
use App\Enums\PcStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Consumable;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Location-safety invariants shared by the Policies (authorization) and the
 * Actions (defense in depth) — the `UserGuard` analogue for Phase 2.4.
 *
 * Realizes FR-LOC-004: a building/floor/room may not be archived while it still
 * holds live occupants, because doing so would strand PC units, assets, stock,
 * or open tickets in a location nobody can see. Callers either reassign the
 * occupants first ({@see ReassignRoomOccupants})
 * or accept the 422.
 *
 * "Live" deliberately excludes history — soft deletes preserve the foreign keys,
 * so a decommissioned PC, a disposed asset, an empty consumable line, or a
 * closed ticket never blocks an archive:
 *
 *  - pc_units    : not archived AND status <> retired
 *  - assets      : not archived AND status NOT IN (retired, disposed)
 *  - consumables : not archived AND quantity_on_hand > 0
 *  - tickets     : not archived AND the current status is flagged `is_open`
 */
class LocationGuard
{
    /**
     * Blocker counts for a single room.
     *
     * @return array{pc_units: int, assets: int, consumables: int, open_tickets: int}
     */
    public function roomBlockers(Room $room): array
    {
        return $this->blockersForRoomIds([$room->getKey()]);
    }

    /**
     * Blocker counts aggregated over every room on the floor (including rooms
     * that are already archived — their occupants are still stranded).
     *
     * @return array{pc_units: int, assets: int, consumables: int, open_tickets: int}
     */
    public function floorBlockers(Floor $floor): array
    {
        return $this->blockersForRoomIds($this->roomIdsForFloors([$floor->getKey()]));
    }

    /**
     * Blocker counts aggregated over every room in the building.
     *
     * @return array{pc_units: int, assets: int, consumables: int, open_tickets: int}
     */
    public function buildingBlockers(Building $building): array
    {
        return $this->blockersForRoomIds($this->roomIdsForBuildings([$building->getKey()]));
    }

    /** True when any blocker exists for the room. */
    public function roomIsInUse(Room $room): bool
    {
        return $this->hasAny($this->roomBlockers($room));
    }

    /** True when any blocker exists anywhere on the floor. */
    public function floorIsInUse(Floor $floor): bool
    {
        return $this->hasAny($this->floorBlockers($floor));
    }

    /** True when any blocker exists anywhere in the building. */
    public function buildingIsInUse(Building $building): bool
    {
        return $this->hasAny($this->buildingBlockers($building));
    }

    /**
     * Per-room breakdown for the rooms that actually block, so the API can tell
     * the administrator exactly where the occupants are.
     *
     * @param  list<int>  $roomIds
     * @return list<array{room: Room, blockers: array{pc_units: int, assets: int, consumables: int, open_tickets: int}}>
     */
    public function breakdown(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        /** @var Collection<int, Room> $rooms */
        $rooms = Room::withTrashed()
            ->with('floor.building')
            ->whereIn('id', $roomIds)
            ->get();

        $breakdown = [];

        foreach ($rooms as $room) {
            $blockers = $this->roomBlockers($room);

            if ($this->hasAny($blockers)) {
                $breakdown[] = ['room' => $room, 'blockers' => $blockers];
            }
        }

        return $breakdown;
    }

    /**
     * Every room id under the given floors (archived rooms included).
     *
     * @param  list<int>  $floorIds
     * @return list<int>
     */
    public function roomIdsForFloors(array $floorIds): array
    {
        if ($floorIds === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = Room::withTrashed()->whereIn('floor_id', $floorIds)->pluck('id')->all();

        return $ids;
    }

    /**
     * Every floor id under the given buildings (archived floors included).
     *
     * @param  list<int>  $buildingIds
     * @return list<int>
     */
    public function floorIdsForBuildings(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = Floor::withTrashed()->whereIn('building_id', $buildingIds)->pluck('id')->all();

        return $ids;
    }

    /**
     * Every room id under the given buildings (archived rows included).
     *
     * @param  list<int>  $buildingIds
     * @return list<int>
     */
    public function roomIdsForBuildings(array $buildingIds): array
    {
        return $this->roomIdsForFloors($this->floorIdsForBuildings($buildingIds));
    }

    /**
     * @param  list<int>  $roomIds
     * @return array{pc_units: int, assets: int, consumables: int, open_tickets: int}
     */
    private function blockersForRoomIds(array $roomIds): array
    {
        if ($roomIds === []) {
            return ['pc_units' => 0, 'assets' => 0, 'consumables' => 0, 'open_tickets' => 0];
        }

        return [
            'pc_units' => PcUnit::query()
                ->whereIn('room_id', $roomIds)
                ->where('status', '<>', PcStatus::Retired->value)
                ->count(),
            'assets' => Asset::query()
                ->whereIn('current_room_id', $roomIds)
                ->whereNotIn('status', [AssetStatus::Retired->value, AssetStatus::Disposed->value])
                ->count(),
            'consumables' => Consumable::query()
                ->whereIn('current_room_id', $roomIds)
                ->where('quantity_on_hand', '>', 0)
                ->count(),
            'open_tickets' => Ticket::query()
                ->whereIn('room_id', $roomIds)
                ->whereHas('status', fn (Builder $q): Builder => $q->where('is_open', true))
                ->count(),
        ];
    }

    /**
     * @param  array{pc_units: int, assets: int, consumables: int, open_tickets: int}  $blockers
     */
    private function hasAny(array $blockers): bool
    {
        return array_sum($blockers) > 0;
    }
}
