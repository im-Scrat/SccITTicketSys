<?php

declare(strict_types=1);

namespace App\Domains\Locations\Services;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cascade soft-delete and restore for the location hierarchy (SRS FR-LOC-004;
 * SDD DD-27).
 *
 * The `floors.building_id` / `rooms.floor_id` foreign keys are `ON DELETE
 * CASCADE`, but a soft delete is an `UPDATE`, so the database cascade never
 * fires. Archiving a building must therefore stamp its floors and rooms
 * explicitly — otherwise the children stay "live" under an invisible parent.
 *
 * **The stamp is the receipt.** One timestamp is computed for the whole cascade
 * and written to every row it touches; restoring reverses exactly the rows
 * carrying that stamp. A room archived on its own last week keeps its own,
 * different `deleted_at`, so restoring the building leaves it archived.
 *
 * `deleted_at` is `timestamptz(0)` — second precision — so an independent
 * archive in the *same second* could otherwise collide with the cascade stamp
 * and be resurrected by a later parent restore. {@see uniqueStampFor} advances
 * the stamp until no descendant already carries it, which makes the receipt
 * unambiguous without adding a column to the baselined schema.
 */
class LocationArchiver
{
    /**
     * Archive a building with its floors and rooms.
     *
     * @return array{floors: int, rooms: int, at: Carbon}
     */
    public function archiveBuilding(Building $building): array
    {
        return DB::transaction(function () use ($building): array {
            /** @var list<int> $floorIds */
            $floorIds = Floor::query()->where('building_id', $building->getKey())->pluck('id')->all();
            /** @var list<int> $allFloorIds */
            $allFloorIds = Floor::withTrashed()->where('building_id', $building->getKey())->pluck('id')->all();

            $at = $this->uniqueStampFor($allFloorIds, $floorIds);

            $rooms = $floorIds === [] ? 0 : Room::query()
                ->whereIn('floor_id', $floorIds)
                ->update(['deleted_at' => $at]);

            $floors = $floorIds === [] ? 0 : Floor::query()
                ->whereIn('id', $floorIds)
                ->update(['deleted_at' => $at]);

            $building->deleted_at = $at;
            $building->save();

            return ['floors' => $floors, 'rooms' => $rooms, 'at' => $at];
        });
    }

    /**
     * Restore a building together with exactly the descendants archived with it.
     *
     * @return array{floors: int, rooms: int}
     */
    public function restoreBuilding(Building $building): array
    {
        $stamp = $building->deleted_at;

        return DB::transaction(function () use ($building, $stamp): array {
            if ($stamp === null) {
                $building->restore();

                return ['floors' => 0, 'rooms' => 0];
            }

            /** @var list<int> $floorIds */
            $floorIds = Floor::withTrashed()
                ->where('building_id', $building->getKey())
                ->where('deleted_at', $stamp)
                ->pluck('id')
                ->all();

            $rooms = $floorIds === [] ? 0 : Room::withTrashed()
                ->whereIn('floor_id', $floorIds)
                ->where('deleted_at', $stamp)
                ->update(['deleted_at' => null]);

            $floors = $floorIds === [] ? 0 : Floor::withTrashed()
                ->whereIn('id', $floorIds)
                ->update(['deleted_at' => null]);

            $building->deleted_at = null;
            $building->save();

            return ['floors' => $floors, 'rooms' => $rooms];
        });
    }

    /**
     * Archive a floor with its rooms. `floors` is always 0 here — the shape is
     * kept identical across levels so callers never branch on it.
     *
     * @return array{floors: int, rooms: int, at: Carbon}
     */
    public function archiveFloor(Floor $floor): array
    {
        return DB::transaction(function () use ($floor): array {
            /** @var list<int> $liveRoomIds */
            $liveRoomIds = Room::query()->where('floor_id', $floor->getKey())->pluck('id')->all();
            /** @var list<int> $allRoomIds */
            $allRoomIds = Room::withTrashed()->where('floor_id', $floor->getKey())->pluck('id')->all();

            $at = $this->uniqueStampForRooms($allRoomIds, $liveRoomIds);

            $rooms = $liveRoomIds === [] ? 0 : Room::query()
                ->whereIn('id', $liveRoomIds)
                ->update(['deleted_at' => $at]);

            $floor->deleted_at = $at;
            $floor->save();

            return ['floors' => 0, 'rooms' => $rooms, 'at' => $at];
        });
    }

    /**
     * Restore a floor together with exactly the rooms archived with it.
     *
     * @return array{floors: int, rooms: int}
     */
    public function restoreFloor(Floor $floor): array
    {
        $stamp = $floor->deleted_at;

        return DB::transaction(function () use ($floor, $stamp): array {
            $rooms = $stamp === null ? 0 : Room::withTrashed()
                ->where('floor_id', $floor->getKey())
                ->where('deleted_at', $stamp)
                ->update(['deleted_at' => null]);

            $floor->deleted_at = null;
            $floor->save();

            return ['floors' => 0, 'rooms' => $rooms];
        });
    }

    /**
     * A cascade stamp that no already-archived descendant shares, so a later
     * restore can identify its own rows unambiguously despite second-precision
     * timestamps.
     *
     * @param  list<int>  $allFloorIds  every floor of the building (archived included)
     * @param  list<int>  $liveFloorIds  the floors this cascade is about to archive
     */
    private function uniqueStampFor(array $allFloorIds, array $liveFloorIds): Carbon
    {
        /** @var list<int> $archivedFloorIds */
        $archivedFloorIds = array_values(array_diff($allFloorIds, $liveFloorIds));

        /** @var list<int> $roomIds */
        $roomIds = $allFloorIds === []
            ? []
            : Room::withTrashed()->whereIn('floor_id', $allFloorIds)->pluck('id')->all();

        $taken = $this->takenStamps($archivedFloorIds, $roomIds);

        return $this->firstFreeStamp($taken);
    }

    /**
     * @param  list<int>  $allRoomIds
     * @param  list<int>  $liveRoomIds
     */
    private function uniqueStampForRooms(array $allRoomIds, array $liveRoomIds): Carbon
    {
        $taken = $this->takenStamps([], array_values(array_diff($allRoomIds, $liveRoomIds)));

        return $this->firstFreeStamp($taken);
    }

    /**
     * Existing `deleted_at` values among descendants, as second-resolution keys.
     *
     * @param  list<int>  $floorIds
     * @param  list<int>  $roomIds
     * @return list<string>
     */
    private function takenStamps(array $floorIds, array $roomIds): array
    {
        $stamps = [];

        if ($floorIds !== []) {
            $stamps = array_merge($stamps, Floor::withTrashed()
                ->whereIn('id', $floorIds)
                ->whereNotNull('deleted_at')
                ->pluck('deleted_at')
                ->all());
        }

        if ($roomIds !== []) {
            $stamps = array_merge($stamps, Room::withTrashed()
                ->whereIn('id', $roomIds)
                ->whereNotNull('deleted_at')
                ->pluck('deleted_at')
                ->all());
        }

        return array_values(array_unique(array_map(
            static fn (mixed $value): string => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : (string) $value,
            $stamps,
        )));
    }

    /**
     * @param  list<string>  $taken
     */
    private function firstFreeStamp(array $taken): Carbon
    {
        $candidate = Carbon::now()->startOfSecond();

        // Bounded: only collides with archives in the same second, and each
        // step moves one second forward.
        while (in_array($candidate->format('Y-m-d H:i:s'), $taken, true)) {
            $candidate = $candidate->copy()->addSecond();
        }

        return $candidate;
    }
}
