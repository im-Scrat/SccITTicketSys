<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Locations\Services\LocationDirectoryQuery;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\HardwareModel;
use App\Models\Manufacturer;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Server-side directory queries for Asset Management (SRS FR-AST-011, FR-PC-001):
 * search, filter, sort and paginate — all in the database, never in PHP.
 *
 * Modelled on {@see LocationDirectoryQuery}, with the same two safety rules:
 * sort columns resolve through fixed allow-lists ({@see ASSET_SORTABLE},
 * {@see PC_SORTABLE}) so a client-supplied `sort` can never reach raw SQL, and
 * user-supplied `%`/`_` in a search term are escaped so they match literally.
 *
 * **Global search.** One term is matched across the record's own columns *and*
 * the entities that give it meaning — catalog model, manufacturer, supplier,
 * location, custodian, QR code, installed parts. Own columns use `ILIKE`;
 * related entities use `EXISTS` subqueries rather than joins, so a row is never
 * duplicated by a one-to-many match and the predicate short-circuits on the
 * first hit. The unanchored `ILIKE` patterns are served by the GIN trigram
 * indexes added in `2026_08_26_100020_add_asset_identity_fields`.
 */
class AssetDirectoryQuery
{
    /** Public sort key => physical column, or a join sentinel resolved below. */
    private const ASSET_SORTABLE = [
        'asset_tag' => 'asset_tag',
        'name' => 'name',
        'status' => 'status',
        'condition' => 'condition',
        'serial_number' => 'serial_number',
        'purchase_date' => 'purchase_date',
        'warranty_expiration' => 'warranty_expiration',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'model' => 'model',
        'category' => 'category',
        'room' => 'room',
        'building' => 'building',
        'supplier' => 'supplier',
        'technician' => 'technician',
    ];

    private const PC_SORTABLE = [
        'unit_code' => 'unit_code',
        'pc_name' => 'pc_name',
        'asset_tag' => 'asset_tag',
        'hostname' => 'hostname',
        'status' => 'status',
        'current_condition' => 'current_condition',
        'purchase_date' => 'purchase_date',
        'warranty_expiration' => 'warranty_expiration',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'room' => 'room',
        'building' => 'building',
    ];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Filtered/sorted serialized-asset query (no pagination). Exposed separately
     * from {@see assets()} so a future exporter can mirror the current view for
     * free, as `UserDirectoryQuery` does for the user export (SDD DD-21).
     *
     * @param  array<string, mixed>  $params
     * @return Builder<Asset>
     */
    public function assetBuilder(array $params): Builder
    {
        $query = Asset::query()->with([
            'hardwareModel.component.manufacturer',
            'supplier',
            'currentRoom.floor.building',
            'assignedTechnician',
        ]);

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applyAssetSearch($query, $this->term($params));
        $this->applyStatus($query, $params['status'] ?? null);
        $this->applyCondition($query, $params['condition'] ?? null, 'assets.condition');
        $this->applyCategory($query, $params['category'] ?? null);
        $this->applyManufacturer($query, $params['manufacturer'] ?? null);
        $this->applySupplier($query, $params['supplier'] ?? null);
        $this->applyTechnician($query, $params['technician'] ?? null);
        $this->applyWarrantyWindow($query, $params['warranty_expiring'] ?? null, 'assets');
        $this->applyLocationScope(
            $query,
            'assets.current_room_id',
            isset($params['building']) ? (string) $params['building'] : null,
            isset($params['floor']) ? (string) $params['floor'] : null,
            isset($params['room']) ? (string) $params['room'] : null,
        );
        $this->applyAssetSort(
            $query,
            (string) ($params['sort'] ?? 'asset_tag'),
            $this->direction($params, 'asc'),
        );

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Asset>
     */
    public function assets(array $params): LengthAwarePaginator
    {
        return $this->assetBuilder($params)->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * Filtered/sorted PC-unit query (no pagination).
     *
     * @param  array<string, mixed>  $params
     * @return Builder<PcUnit>
     */
    public function pcUnitBuilder(array $params): Builder
    {
        $query = PcUnit::query()
            ->with(['room.floor.building', 'specification'])
            ->withCount(['componentInstallations', 'tickets']);

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applyPcSearch($query, $this->term($params));
        $this->applyPcStatus($query, $params['status'] ?? null);
        $this->applyCondition($query, $params['condition'] ?? null, 'pc_units.current_condition');
        $this->applyWarrantyWindow($query, $params['warranty_expiring'] ?? null, 'pc_units');
        $this->applyLocationScope(
            $query,
            'pc_units.room_id',
            isset($params['building']) ? (string) $params['building'] : null,
            isset($params['floor']) ? (string) $params['floor'] : null,
            isset($params['room']) ? (string) $params['room'] : null,
        );
        $this->applyPcSort(
            $query,
            (string) ($params['sort'] ?? 'unit_code'),
            $this->direction($params, 'asc'),
        );

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, PcUnit>
     */
    public function pcUnits(array $params): LengthAwarePaginator
    {
        return $this->pcUnitBuilder($params)->paginate($this->perPage($params))->withQueryString();
    }

    /* ------------------------------------------------------------ helpers */

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
     * @param  Builder<Asset>|Builder<PcUnit>  $query
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
     * Escape a user-supplied term so `%` and `_` match literally, then wrap it
     * for a substring match.
     */
    private function pattern(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }

    /**
     * Global asset search (FR-AST-011): tag, name, serial and barcode on the row
     * itself, then catalog, supplier, location, custodian and QR by subquery.
     *
     * @param  Builder<Asset>  $query
     */
    private function applyAssetSearch(Builder $query, ?string $search): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = $this->pattern($search);

        $query->where(function (Builder $q) use ($term): void {
            foreach (['asset_tag', 'name', 'serial_number', 'barcode'] as $column) {
                $q->orWhere("assets.{$column}", 'ILIKE', $term);
            }

            // Catalog: model name/number, the component name, and the brand.
            $q->orWhereExists(function (QueryBuilder $sub) use ($term): void {
                $sub->selectRaw('1')
                    ->from('hardware_models')
                    ->leftJoin('hardware_components', 'hardware_components.id', '=', 'hardware_models.hardware_component_id')
                    ->leftJoin('manufacturers', 'manufacturers.id', '=', 'hardware_components.manufacturer_id')
                    ->whereColumn('hardware_models.id', 'assets.hardware_model_id')
                    ->where(function (QueryBuilder $inner) use ($term): void {
                        $inner->where('hardware_models.model_name', 'ILIKE', $term)
                            ->orWhere('hardware_models.model_number', 'ILIKE', $term)
                            ->orWhere('hardware_components.name', 'ILIKE', $term)
                            ->orWhere('manufacturers.name', 'ILIKE', $term);
                    });
            });

            $q->orWhereExists(function (QueryBuilder $sub) use ($term): void {
                $sub->selectRaw('1')
                    ->from('suppliers')
                    ->whereColumn('suppliers.id', 'assets.supplier_id')
                    ->where('suppliers.name', 'ILIKE', $term);
            });

            $q->orWhereExists(fn (QueryBuilder $sub) => $this->locationMatch($sub, 'assets.current_room_id', $term));
            $q->orWhereExists(fn (QueryBuilder $sub) => $this->technicianMatch($sub, 'assets.assigned_technician_id', $term));

            $q->orWhereExists(function (QueryBuilder $sub) use ($term): void {
                $sub->selectRaw('1')
                    ->from('qr_codes')
                    ->whereColumn('qr_codes.asset_id', 'assets.id')
                    ->where('qr_codes.code', 'ILIKE', $term);
            });
        });
    }

    /**
     * @param  Builder<PcUnit>  $query
     */
    private function applyPcSearch(Builder $query, ?string $search): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = $this->pattern($search);

        $query->where(function (Builder $q) use ($term): void {
            foreach (['unit_code', 'pc_name', 'asset_tag', 'hostname', 'serial_number', 'brand', 'model'] as $column) {
                $q->orWhere("pc_units.{$column}", 'ILIKE', $term);
            }

            $q->orWhereExists(fn (QueryBuilder $sub) => $this->locationMatch($sub, 'pc_units.room_id', $term));

            $q->orWhereExists(function (QueryBuilder $sub) use ($term): void {
                $sub->selectRaw('1')
                    ->from('qr_codes')
                    ->whereColumn('qr_codes.pc_unit_id', 'pc_units.id')
                    ->where('qr_codes.code', 'ILIKE', $term);
            });

            // Installed hardware: find a PC by a part inside it (FR-PC-004).
            $q->orWhereExists(function (QueryBuilder $sub) use ($term): void {
                $sub->selectRaw('1')
                    ->from('pc_component_installations')
                    ->leftJoin('assets', 'assets.id', '=', 'pc_component_installations.asset_id')
                    ->leftJoin('hardware_models', 'hardware_models.id', '=', 'assets.hardware_model_id')
                    ->leftJoin('hardware_components', 'hardware_components.id', '=', 'hardware_models.hardware_component_id')
                    ->whereColumn('pc_component_installations.pc_unit_id', 'pc_units.id')
                    ->where(function (QueryBuilder $inner) use ($term): void {
                        $inner->where('assets.asset_tag', 'ILIKE', $term)
                            ->orWhere('assets.serial_number', 'ILIKE', $term)
                            ->orWhere('hardware_models.model_name', 'ILIKE', $term)
                            ->orWhere('hardware_components.name', 'ILIKE', $term);
                    });
            });
        });
    }

    /**
     * Match a room, its floor or its building by name/code. Archived parents are
     * deliberately reachable — the directory must still resolve the location of a
     * row whose building was archived.
     */
    private function locationMatch(QueryBuilder $sub, string $roomIdColumn, string $term): QueryBuilder
    {
        return $sub->selectRaw('1')
            ->from('rooms')
            ->leftJoin('floors', 'floors.id', '=', 'rooms.floor_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
            ->whereColumn('rooms.id', $roomIdColumn)
            ->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('rooms.name', 'ILIKE', $term)
                    ->orWhere('rooms.code', 'ILIKE', $term)
                    ->orWhere('rooms.room_number', 'ILIKE', $term)
                    ->orWhere('floors.name', 'ILIKE', $term)
                    ->orWhere('buildings.name', 'ILIKE', $term)
                    ->orWhere('buildings.code', 'ILIKE', $term);
            });
    }

    /** Match the assigned custodian by first, last or full name. */
    private function technicianMatch(QueryBuilder $sub, string $userIdColumn, string $term): QueryBuilder
    {
        return $sub->selectRaw('1')
            ->from('users')
            ->whereColumn('users.id', $userIdColumn)
            ->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('users.first_name', 'ILIKE', $term)
                    ->orWhere('users.last_name', 'ILIKE', $term)
                    ->orWhereRaw("(users.first_name || ' ' || users.last_name) ILIKE ?", [$term]);
            });
    }

    /**
     * `status` accepts one value or a comma-separated list, so a dashboard tile
     * can link to "retired or disposed" in one hop.
     *
     * @param  Builder<Asset>  $query
     */
    private function applyStatus(Builder $query, mixed $status): void
    {
        $values = $this->allowed($status, AssetStatus::values());

        if ($values !== []) {
            $query->whereIn('assets.status', $values);
        }
    }

    /**
     * @param  Builder<PcUnit>  $query
     */
    private function applyPcStatus(Builder $query, mixed $status): void
    {
        $values = $this->allowed($status, PcStatus::values());

        if ($values !== []) {
            $query->whereIn('pc_units.status', $values);
        }
    }

    /**
     * @param  Builder<Asset>|Builder<PcUnit>  $query
     */
    private function applyCondition(Builder $query, mixed $condition, string $column): void
    {
        $values = $this->allowed($condition, PcCondition::values());

        if ($values !== []) {
            $query->whereIn($column, $values);
        }
    }

    /**
     * Narrow a raw filter value to the members of an allow-list. Anything the
     * enum does not know is dropped rather than passed through — the same
     * deny-by-default stance the sort allow-lists take.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function allowed(mixed $value, array $allowed): array
    {
        if ($value === null || $value === '' || $value === 'all') {
            return [];
        }

        $candidates = is_array($value) ? $value : explode(',', (string) $value);

        $clean = [];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && in_array($candidate, $allowed, true)) {
                $clean[] = $candidate;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Category is the catalog component type, one level above the asset.
     *
     * @param  Builder<Asset>  $query
     */
    private function applyCategory(Builder $query, mixed $category): void
    {
        $values = $this->allowed($category, ComponentType::values());

        if ($values === []) {
            return;
        }

        $query->whereIn(
            'assets.hardware_model_id',
            HardwareModel::query()
                ->select('hardware_models.id')
                ->join('hardware_components', 'hardware_components.id', '=', 'hardware_models.hardware_component_id')
                ->whereIn('hardware_components.component_type', $values),
        );
    }

    /**
     * @param  Builder<Asset>  $query
     */
    private function applyManufacturer(Builder $query, mixed $manufacturer): void
    {
        if (! $this->isSet(is_string($manufacturer) ? $manufacturer : null) && ! is_int($manufacturer)) {
            return;
        }

        $query->whereIn(
            'assets.hardware_model_id',
            HardwareModel::query()
                ->select('hardware_models.id')
                ->join('hardware_components', 'hardware_components.id', '=', 'hardware_models.hardware_component_id')
                ->whereIn(
                    'hardware_components.manufacturer_id',
                    Manufacturer::withTrashed()->select('id')->where('name', (string) $manufacturer),
                ),
        );
    }

    /**
     * @param  Builder<Asset>  $query
     */
    private function applySupplier(Builder $query, mixed $supplier): void
    {
        if (! $this->isSet(is_string($supplier) ? $supplier : null)) {
            return;
        }

        $query->whereIn(
            'assets.supplier_id',
            Supplier::withTrashed()->select('id')->where('name', (string) $supplier),
        );
    }

    /**
     * `technician` is a user uuid, or the sentinel `unassigned`.
     *
     * @param  Builder<Asset>  $query
     */
    private function applyTechnician(Builder $query, mixed $technician): void
    {
        if (! $this->isSet(is_string($technician) ? $technician : null)) {
            return;
        }

        if ($technician === 'unassigned') {
            $query->whereNull('assets.assigned_technician_id');

            return;
        }

        $query->whereIn(
            'assets.assigned_technician_id',
            User::withTrashed()->select('id')->where('uuid', (string) $technician),
        );
    }

    /**
     * Warranties lapsing inside a day window — the dashboard's "expiring soon"
     * tile links straight here. Already-lapsed warranties are excluded: those are
     * a different question from "act before this runs out".
     *
     * @param  Builder<Asset>|Builder<PcUnit>  $query
     */
    private function applyWarrantyWindow(Builder $query, mixed $days, string $table): void
    {
        if ($days === null || $days === '' || $days === 'all') {
            return;
        }

        $window = max(1, min((int) $days, 3650));

        $query->whereNotNull("{$table}.warranty_expiration")
            ->whereBetween("{$table}.warranty_expiration", [
                now()->toDateString(),
                now()->addDays($window)->toDateString(),
            ]);
    }

    /**
     * Scope to a building, floor and/or room, all addressed by uuid.
     *
     * Scoped through id subqueries rather than `whereHas`, so an archived parent
     * still resolves — the directory must be able to show the assets of an
     * archived building when `trashed=with|only` is requested (the same reasoning
     * as {@see LocationDirectoryQuery} `applyRoomScope`).
     *
     * @param  Builder<Asset>|Builder<PcUnit>  $query
     */
    private function applyLocationScope(
        Builder $query,
        string $roomColumn,
        ?string $buildingUuid,
        ?string $floorUuid,
        ?string $roomUuid,
    ): void {
        if ($this->isSet($roomUuid)) {
            $query->whereIn($roomColumn, Room::withTrashed()->select('id')->where('uuid', $roomUuid));
        }

        if ($this->isSet($floorUuid)) {
            $query->whereIn(
                $roomColumn,
                Room::withTrashed()->select('id')->whereIn(
                    'floor_id',
                    Floor::withTrashed()->select('id')->where('uuid', $floorUuid),
                ),
            );
        }

        if ($this->isSet($buildingUuid)) {
            $query->whereIn(
                $roomColumn,
                Room::withTrashed()->select('id')->whereIn(
                    'floor_id',
                    Floor::withTrashed()->select('id')->whereIn(
                        'building_id',
                        Building::withTrashed()->select('id')->where('uuid', $buildingUuid),
                    ),
                ),
            );
        }
    }

    private function isSet(?string $value): bool
    {
        return $value !== null && $value !== '' && $value !== 'all';
    }

    /**
     * @param  Builder<Asset>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applyAssetSort(Builder $query, string $sort, string $direction): void
    {
        $column = self::ASSET_SORTABLE[$sort] ?? 'asset_tag';

        match ($column) {
            'model', 'category' => $this->sortByCatalog($query, $column, $direction),
            'room', 'building' => $this->sortByLocation($query, 'assets', 'current_room_id', $column, $direction),
            'supplier' => $this->sortByRelated($query, 'assets', 'suppliers', 'supplier_id', 'name', $direction),
            'technician' => $this->sortByTechnician($query, $direction),
            default => $query->orderBy("assets.{$column}", $direction),
        };

        // Stable tiebreak: `asset_tag` is unique, so pagination never repeats or
        // drops a row when the sorted column holds duplicates.
        $query->orderBy('assets.asset_tag');
    }

    /**
     * @param  Builder<PcUnit>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applyPcSort(Builder $query, string $sort, string $direction): void
    {
        $column = self::PC_SORTABLE[$sort] ?? 'unit_code';

        match ($column) {
            'room', 'building' => $this->sortByLocation($query, 'pc_units', 'room_id', $column, $direction),
            default => $query->orderBy("pc_units.{$column}", $direction),
        };

        $query->orderBy('pc_units.unit_code');
    }

    /**
     * @param  Builder<Asset>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByCatalog(Builder $query, string $column, string $direction): void
    {
        $query->leftJoin('hardware_models', 'hardware_models.id', '=', 'assets.hardware_model_id')
            ->leftJoin('hardware_components', 'hardware_components.id', '=', 'hardware_models.hardware_component_id')
            ->select('assets.*');

        $column === 'model'
            ? $query->orderBy('hardware_models.model_name', $direction)
            : $query->orderBy('hardware_components.component_type', $direction);
    }

    /**
     * @param  Builder<Asset>|Builder<PcUnit>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByLocation(
        Builder $query,
        string $table,
        string $roomColumn,
        string $column,
        string $direction,
    ): void {
        $query->leftJoin('rooms', 'rooms.id', '=', "{$table}.{$roomColumn}")
            ->leftJoin('floors', 'floors.id', '=', 'rooms.floor_id')
            ->leftJoin('buildings', 'buildings.id', '=', 'floors.building_id')
            ->select("{$table}.*");

        $column === 'building'
            ? $query->orderBy('buildings.name', $direction)->orderBy('floors.floor_number')->orderBy('rooms.name')
            : $query->orderBy('rooms.name', $direction);
    }

    /**
     * @param  Builder<Asset>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByRelated(
        Builder $query,
        string $table,
        string $related,
        string $foreignKey,
        string $orderColumn,
        string $direction,
    ): void {
        $query->leftJoin($related, "{$related}.id", '=', "{$table}.{$foreignKey}")
            ->select("{$table}.*")
            ->orderBy("{$related}.{$orderColumn}", $direction);
    }

    /**
     * @param  Builder<Asset>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByTechnician(Builder $query, string $direction): void
    {
        $query->leftJoin('users', 'users.id', '=', 'assets.assigned_technician_id')
            ->select('assets.*')
            ->orderBy('users.last_name', $direction)
            ->orderBy('users.first_name', $direction);
    }
}
