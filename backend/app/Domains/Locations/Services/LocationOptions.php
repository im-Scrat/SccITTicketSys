<?php

declare(strict_types=1);

namespace App\Domains\Locations\Services;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The selectable-location source behind the reusable location picker
 * (FR-LOC-005): rooms, floors and buildings offered as location context to
 * ticketing, assets and maintenance.
 *
 * A location is **selectable** only when the whole chain is available: the
 * building is active and not archived, the floor is not archived, and the room
 * is active and not archived. Archiving or deactivating a building therefore
 * removes all of its floors and rooms from every picker without touching the
 * child rows — the "makes its child floors/rooms unavailable" half of
 * FR-LOC-004.
 *
 * Results are hard-limited: a picker never streams an unbounded estate to the
 * client (NFR-PERF); the client narrows with `search`/`building`/`room_type`.
 */
class LocationOptions
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * Selectable rooms, ordered building → floor → room.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, Room>
     */
    public function rooms(array $params = []): Collection
    {
        $query = Room::query()
            ->with(['floor.building'])
            ->where('rooms.is_active', true)
            ->whereHas('floor', fn (Builder $floor): Builder => $floor->whereHas(
                'building',
                fn (Builder $building): Builder => $building->where('is_active', true),
            ));

        $this->applySearch($query, isset($params['search']) ? (string) $params['search'] : null);
        $this->applyBuilding($query, isset($params['building']) ? (string) $params['building'] : null);
        $this->applyFloor($query, isset($params['floor']) ? (string) $params['floor'] : null);
        $this->applyRoomType($query, isset($params['room_type']) ? (string) $params['room_type'] : null);

        return $query
            ->leftJoin('floors', 'floors.id', '=', 'rooms.floor_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
            ->select('rooms.*')
            ->orderBy('buildings.name')
            ->orderBy('floors.floor_number')
            ->orderBy('rooms.name')
            ->limit($this->limit($params))
            ->get();
    }

    /**
     * Selectable buildings for a cascading picker.
     *
     * @return Collection<int, Building>
     */
    public function buildings(): Collection
    {
        return Building::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Selectable floors, optionally scoped to one building (by uuid).
     *
     * @return Collection<int, Floor>
     */
    public function floors(?string $buildingUuid = null): Collection
    {
        $query = Floor::query()
            ->with('building')
            ->whereHas('building', fn (Builder $q): Builder => $q->where('is_active', true));

        if ($buildingUuid !== null && $buildingUuid !== '') {
            $query->whereHas('building', fn (Builder $q): Builder => $q->where('uuid', $buildingUuid));
        }

        return $query
            ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
            ->select('floors.*')
            ->orderBy('buildings.name')
            ->orderBy('floors.floor_number')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function limit(array $params): int
    {
        $limit = (int) ($params['limit'] ?? self::DEFAULT_LIMIT);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    /**
     * Matches the room name/code/number or the parent building name, with
     * user-supplied wildcards escaped.
     *
     * @param  Builder<Room>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $query->where(function (Builder $q) use ($term): void {
            $q->where('rooms.name', 'ILIKE', $term)
                ->orWhere('rooms.code', 'ILIKE', $term)
                ->orWhere('rooms.room_number', 'ILIKE', $term)
                ->orWhereHas(
                    'floor.building',
                    fn (Builder $building): Builder => $building->where('name', 'ILIKE', $term),
                );
        });
    }

    /**
     * @param  Builder<Room>  $query
     */
    private function applyBuilding(Builder $query, ?string $buildingUuid): void
    {
        if ($buildingUuid === null || $buildingUuid === '' || $buildingUuid === 'all') {
            return;
        }

        $query->whereHas('floor.building', fn (Builder $q): Builder => $q->where('uuid', $buildingUuid));
    }

    /**
     * @param  Builder<Room>  $query
     */
    private function applyFloor(Builder $query, ?string $floorUuid): void
    {
        if ($floorUuid === null || $floorUuid === '' || $floorUuid === 'all') {
            return;
        }

        $query->whereHas('floor', fn (Builder $q): Builder => $q->where('uuid', $floorUuid));
    }

    /**
     * @param  Builder<Room>  $query
     */
    private function applyRoomType(Builder $query, ?string $roomType): void
    {
        if ($roomType === null || $roomType === '' || $roomType === 'all') {
            return;
        }

        $query->where('rooms.room_type', $roomType);
    }
}
