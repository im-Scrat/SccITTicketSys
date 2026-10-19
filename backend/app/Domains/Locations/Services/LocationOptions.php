<?php

declare(strict_types=1);

namespace App\Domains\Locations\Services;

use App\Domains\Tickets\Http\Requests\StoreTicketRequest;
use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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
 * That predicate is defined **once**, in {@see selectableRooms()}, and shared
 * with the places that must enforce exactly what the picker offers — the
 * room-scoped equipment lookup and {@see StoreTicketRequest} (SDD DD-78). A
 * list filter with no matching single-record check is an IDOR; one predicate
 * for both is how they cannot drift apart.
 *
 * Results are hard-limited: a picker never streams an unbounded estate to the
 * client (NFR-PERF); the client narrows with `search`/`building`/`room_type`.
 */
class LocationOptions
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * The selectable-room predicate, unfiltered and unordered: the room is
     * active and not archived, its floor is not archived, and its building is
     * active and not archived. (Archived rows are excluded by each model's
     * soft-delete scope, which `whereHas` applies to the floor and building.)
     *
     * @return Builder<Room>
     */
    public function selectableRooms(): Builder
    {
        return Room::query()
            ->where('rooms.is_active', true)
            ->whereHas('floor', fn (Builder $floor): Builder => $floor->whereHas(
                'building',
                fn (Builder $building): Builder => $building->where('is_active', true),
            ));
    }

    /**
     * One selectable room by uuid, or null — for a room that does not exist and
     * a room that exists but is not selectable alike, so a caller cannot use the
     * difference as an existence oracle. A malformed uuid is null too, rather
     * than a PostgreSQL `uuid` cast error.
     */
    public function findSelectable(string $uuid): ?Room
    {
        if (! Str::isUuid($uuid)) {
            return null;
        }

        return $this->selectableRooms()->where('rooms.uuid', $uuid)->first();
    }

    /**
     * Selectable rooms, ordered building → floor → room — or, with
     * `sort=type`, laboratories first, then offices, then every other type,
     * each group in that same order (the report form's "Where is it?" list,
     * where the room a teacher reports from is most often a lab).
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, Room>
     */
    public function rooms(array $params = []): Collection
    {
        $query = $this->selectableRooms()->with(['floor.building']);

        $building = isset($params['building']) ? (string) $params['building'] : null;

        // Inside one building every room shares the building's name, so
        // matching on it would make a search for that name return the whole
        // list — the opposite of narrowing.
        $this->applySearch(
            $query,
            isset($params['search']) ? (string) $params['search'] : null,
            matchBuildingName: ! $this->scopesToBuilding($building),
        );
        $this->applyBuilding($query, $building);
        $this->applyFloor($query, isset($params['floor']) ? (string) $params['floor'] : null);
        $this->applyRoomType($query, isset($params['room_type']) ? (string) $params['room_type'] : null);

        $query
            ->leftJoin('floors', 'floors.id', '=', 'rooms.floor_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
            ->select('rooms.*');

        if (($params['sort'] ?? null) === 'type') {
            $query->orderByRaw(
                'CASE rooms.room_type WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
                [RoomType::Laboratory->value, RoomType::Office->value],
            );
        }

        return $query
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
     * Matches the room name/code/number — and, unless the query is already
     * scoped to one building, the parent building name — with user-supplied
     * wildcards escaped.
     *
     * @param  Builder<Room>  $query
     */
    private function applySearch(Builder $query, ?string $search, bool $matchBuildingName = true): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $query->where(function (Builder $q) use ($term, $matchBuildingName): void {
            $q->where('rooms.name', 'ILIKE', $term)
                ->orWhere('rooms.code', 'ILIKE', $term)
                ->orWhere('rooms.room_number', 'ILIKE', $term);

            if ($matchBuildingName) {
                $q->orWhereHas(
                    'floor.building',
                    fn (Builder $building): Builder => $building->where('name', 'ILIKE', $term),
                );
            }
        });
    }

    /** Does this `building` parameter narrow the query to one building? */
    private function scopesToBuilding(?string $buildingUuid): bool
    {
        return $buildingUuid !== null && $buildingUuid !== '' && $buildingUuid !== 'all';
    }

    /**
     * @param  Builder<Room>  $query
     */
    private function applyBuilding(Builder $query, ?string $buildingUuid): void
    {
        if (! $this->scopesToBuilding($buildingUuid)) {
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
