<?php

declare(strict_types=1);

namespace App\Domains\Locations\Services;

use App\Domains\Identity\Services\UserDirectoryQuery;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Server-side directory queries for Location Management (SRS FR-LOC): search,
 * filter, sort and paginate — all in the database, never in PHP.
 *
 * Modelled on {@see UserDirectoryQuery}: sort
 * columns resolve through fixed allow-lists ({@see BUILDING_SORTABLE},
 * {@see ROOM_SORTABLE}) so a client-supplied `sort` value can never reach raw
 * SQL, and user-supplied `%`/`_` in a search term are escaped so they match
 * literally.
 */
class LocationDirectoryQuery
{
    /** Public sort key => physical column (or a join sentinel resolved below). */
    private const BUILDING_SORTABLE = [
        'name' => 'name',
        'code' => 'code',
        'floors_count' => 'floors_count',
        'rooms_count' => 'rooms_count',
        'created_at' => 'created_at',
    ];

    private const ROOM_SORTABLE = [
        'name' => 'name',
        'code' => 'code',
        'room_type' => 'room_type',
        'capacity' => 'capacity',
        'building' => 'building',
        'floor_number' => 'floor_number',
        'pc_units_count' => 'pc_units_count',
        'created_at' => 'created_at',
    ];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Filtered/sorted building query (no pagination).
     *
     * @param  array<string, mixed>  $params
     * @return Builder<Building>
     */
    public function buildingBuilder(array $params): Builder
    {
        $query = Building::query()->withCount(['floors', 'rooms']);

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applySearch($query, $this->term($params), ['name', 'code', 'address']);
        $this->applyActive($query, $params['active'] ?? null);
        $this->applyBuildingSort(
            $query,
            (string) ($params['sort'] ?? 'name'),
            $this->direction($params, 'asc'),
        );

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Building>
     */
    public function buildings(array $params): LengthAwarePaginator
    {
        return $this->buildingBuilder($params)->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * Filtered/sorted room query (no pagination).
     *
     * @param  array<string, mixed>  $params
     * @return Builder<Room>
     */
    public function roomBuilder(array $params): Builder
    {
        $query = Room::query()
            ->with(['floor.building'])
            ->withCount(['pcUnits']);

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applySearch($query, $this->term($params), ['name', 'code', 'room_number']);
        $this->applyActive($query, $params['active'] ?? null);
        $this->applyRoomType($query, isset($params['room_type']) ? (string) $params['room_type'] : null);
        $this->applyRoomScope(
            $query,
            isset($params['building']) ? (string) $params['building'] : null,
            isset($params['floor']) ? (string) $params['floor'] : null,
        );
        $this->applyRoomSort(
            $query,
            (string) ($params['sort'] ?? 'name'),
            $this->direction($params, 'asc'),
        );

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Room>
     */
    public function rooms(array $params): LengthAwarePaginator
    {
        return $this->roomBuilder($params)->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * Floors of one building, ordered by floor number. Small, bounded set — the
     * tree and the building detail page consume it whole, so it is not paginated.
     *
     * @return Collection<int, Floor>
     */
    public function floorsOfBuilding(Building $building, string $trashed = 'without')
    {
        $query = Floor::query()
            ->where('building_id', $building->getKey())
            ->withCount('rooms');

        $this->applyTrashed($query, $trashed);

        return $query->orderBy('floor_number')->get();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function perPage(array $params): int
    {
        $perPage = (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function term(array $params): ?string
    {
        return isset($params['search']) ? (string) $params['search'] : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return 'asc'|'desc'
     */
    private function direction(array $params, string $default): string
    {
        $direction = strtolower((string) ($params['direction'] ?? $default));

        return $direction === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @param  Builder<Building>|Builder<Floor>|Builder<Room>  $query
     */
    private function applyTrashed(Builder $query, string $mode): void
    {
        match ($mode) {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => null, // 'without' — the default scope already excludes trashed.
        };
    }

    /**
     * Case-insensitive substring match across the given columns. User-supplied
     * wildcards are escaped so `%`/`_` are treated literally.
     *
     * @param  Builder<Building>|Builder<Room>  $query
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $query, ?string $search, array $columns): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $query->where(function (Builder $q) use ($term, $columns): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $q->where($column, 'ILIKE', $term)
                    : $q->orWhere($column, 'ILIKE', $term);
            }
        });
    }

    /**
     * `active` accepts a tri-state: null/'all' (no filter), true/'1'/'active',
     * false/'0'/'inactive'.
     *
     * @param  Builder<Building>|Builder<Room>  $query
     */
    private function applyActive(Builder $query, mixed $active): void
    {
        if ($active === null || $active === '' || $active === 'all') {
            return;
        }

        $wanted = match (true) {
            is_bool($active) => $active,
            default => in_array((string) $active, ['1', 'true', 'active', 'yes'], true),
        };

        $query->where('is_active', $wanted);
    }

    /**
     * @param  Builder<Room>  $query
     */
    private function applyRoomType(Builder $query, ?string $roomType): void
    {
        if ($roomType === null || $roomType === '' || $roomType === 'all') {
            return;
        }

        $query->where('room_type', $roomType);
    }

    /**
     * Scope rooms to a building and/or a floor, both addressed by uuid.
     *
     * @param  Builder<Room>  $query
     */
    private function applyRoomScope(Builder $query, ?string $buildingUuid, ?string $floorUuid): void
    {
        // Scoped through id subqueries rather than `whereHas`, so an archived
        // parent still resolves: the directory must be able to show the rooms of
        // an archived building when `trashed=with|only` is requested.
        if ($floorUuid !== null && $floorUuid !== '' && $floorUuid !== 'all') {
            $query->whereIn(
                'floor_id',
                Floor::withTrashed()->where('uuid', $floorUuid)->select('id'),
            );
        }

        if ($buildingUuid !== null && $buildingUuid !== '' && $buildingUuid !== 'all') {
            $query->whereIn(
                'floor_id',
                Floor::withTrashed()
                    ->whereIn(
                        'building_id',
                        Building::withTrashed()->where('uuid', $buildingUuid)->select('id'),
                    )
                    ->select('id'),
            );
        }
    }

    /**
     * @param  Builder<Building>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applyBuildingSort(Builder $query, string $sort, string $direction): void
    {
        $column = self::BUILDING_SORTABLE[$sort] ?? 'name';

        $query->orderBy($column, $direction);

        if ($column !== 'name') {
            $query->orderBy('name');
        }
    }

    /**
     * Room sorts that address the parent hierarchy join through floors/buildings;
     * everything else orders on a physical `rooms` column.
     *
     * @param  Builder<Room>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applyRoomSort(Builder $query, string $sort, string $direction): void
    {
        $column = self::ROOM_SORTABLE[$sort] ?? 'name';

        if ($column === 'building' || $column === 'floor_number') {
            $query->leftJoin('floors', 'floors.id', '=', 'rooms.floor_id')
                ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
                ->select('rooms.*');

            $column === 'building'
                ? $query->orderBy('buildings.name', $direction)->orderBy('floors.floor_number')
                : $query->orderBy('floors.floor_number', $direction)->orderBy('buildings.name');

            $query->orderBy('rooms.name');

            return;
        }

        $query->orderBy($column, $direction);

        if ($column !== 'name') {
            $query->orderBy('name');
        }
    }
}
