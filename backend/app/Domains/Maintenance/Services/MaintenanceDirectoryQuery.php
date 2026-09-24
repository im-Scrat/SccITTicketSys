<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Server-side maintenance queries (SRS FR-MNT-003/007/011).
 *
 * Modelled on {@see TicketDirectoryQuery}: fixed sort allow-lists, escaped
 * search terms, everything resolved in the database, and — the part that
 * matters — **every entry point applies {@see MaintenanceVisibility} first**.
 * Scoping is not something a caller opts into; {@see base()} does it before any
 * filter is considered, so there is no code path in this class that can produce
 * an unscoped maintenance list.
 *
 * One difference from Tickets: `maintenance_records` carries no generated
 * `tsvector`, so search is an escaped `ILIKE` across title, diagnosis and
 * resolution. That is the honest trade for a table this size — adding a search
 * vector would be a schema change no requirement asks for, and the maintenance
 * directory is filtered far more often than it is searched.
 *
 * Four entry points, all offset-paginated, because all four are tables:
 * {@see queue()} is a technician's live work, {@see history()} is what they have
 * finished, {@see scheduled()} is the preventive horizon, and
 * {@see directory()} is the Administrator's cross-estate view.
 */
class MaintenanceDirectoryQuery
{
    /** Public sort key => physical column, or a join sentinel resolved below. */
    private const SORTABLE = [
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'title' => 'title',
        'scheduled_for' => 'scheduled_for',
        'started_at' => 'started_at',
        'completed_at' => 'completed_at',
        'maintenance_date' => 'maintenance_date',
        'downtime' => 'downtime_minutes',
        'cost' => 'cost',
        'status' => 'status',
        'type' => 'type',
        'technician' => 'technician',
    ];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function __construct(private readonly MaintenanceVisibility $visibility) {}

    /**
     * A technician's live work — open records they own, soonest first.
     *
     * Scoped by {@see MaintenanceVisibility::scopeOwn()} rather than
     * {@see MaintenanceVisibility::scope()} so the page means the same thing to
     * an Administrator as to a Technician. "My maintenance" that silently
     * becomes "all maintenance" for one role is a page that lies to the other.
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, MaintenanceRecord>
     */
    public function queue(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);
        $this->visibility->scopeOwn($query, $user);

        $query->whereIn('maintenance_records.status', $this->openStatusValues());

        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);

        // Overdue first, then the soonest scheduled; unscheduled work sinks to
        // the bottom rather than sorting as "infinitely urgent".
        $query->orderByRaw('scheduled_for IS NULL')
            ->orderBy('maintenance_records.scheduled_for')
            ->orderByDesc('maintenance_records.id');

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * A technician's finished work — completed or cancelled, most recent first.
     *
     * Readable permanently: a technician needs their own maintenance history for
     * reference and audit, which is why read access outlives the visit even
     * though every write ability lapses with it.
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, MaintenanceRecord>
     */
    public function history(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);
        $this->visibility->scopeOwn($query, $user);

        $query->whereIn('maintenance_records.status', [
            MaintenanceStatus::Completed->value,
            MaintenanceStatus::Cancelled->value,
        ]);

        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);
        $this->applySort($query, (string) ($params['sort'] ?? 'completed_at'), $this->direction($params, 'desc'));

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * The preventive horizon — open records with a date, soonest first
     * (SRS FR-MNT-007).
     *
     * Scoped to the caller's own work unless they ask for the estate and are
     * entitled to it, so a technician's "scheduled" page is their calendar and
     * an administrator's is the site's.
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, MaintenanceRecord>
     */
    public function scheduled(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);

        if (! $this->wantsEstate($params, $user)) {
            $this->visibility->scopeOwn($query, $user);
        }

        $query->whereIn('maintenance_records.status', $this->openStatusValues())
            ->whereNotNull('maintenance_records.scheduled_for');

        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);

        $query->orderBy('maintenance_records.scheduled_for')
            ->orderByDesc('maintenance_records.id');

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * The Administrator directory — the whole estate, every filter.
     *
     * Still runs through {@see base()}, so the scope is applied here exactly as
     * everywhere else. It happens to be unconstrained *because the caller is an
     * administrator*, not because this method skips the check — which is what
     * keeps the rule in one place.
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, MaintenanceRecord>
     */
    public function directory(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);
        $this->applyAdministrativeFilters($query, $params);
        $this->applySort($query, (string) ($params['sort'] ?? 'created_at'), $this->direction($params, 'desc'));

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /* --------------------------------------------------------------- base */

    /**
     * Every query starts here: visibility-scoped and eager-loaded.
     *
     * The eager loads are not decoration. A maintenance row renders its type,
     * technician, target machine and that machine's room on every line, so
     * without them a 20-row page is well over a hundred queries.
     *
     * @return Builder<MaintenanceRecord>
     */
    private function base(User $user): Builder
    {
        $query = MaintenanceRecord::query()->with([
            'type:id,name,slug,is_preventive',
            'technician:id,uuid,first_name,last_name',
            'createdBy:id,uuid,first_name,last_name',
            'ticket:id,uuid,ticket_number,title',
            'pcUnit:id,uuid,unit_code,pc_name,room_id,status,current_condition',
            'pcUnit.room:id,uuid,name,floor_id',
            'pcUnit.room.floor:id,uuid,name,floor_number,building_id',
            'pcUnit.room.floor.building:id,uuid,name,code',
            'asset:id,uuid,asset_tag,name,current_room_id,status',
            'asset.currentRoom:id,uuid,name,floor_id',
            'asset.currentRoom.floor:id,uuid,name,floor_number,building_id',
            'asset.currentRoom.floor.building:id,uuid,name,code',
        ])->withCount([
            'checklists',
            'checklists as completed_checklists_count' => fn (Builder $q) => $q->where('is_completed', true),
            'images',
            'notes',
        ]);

        return $this->visibility->scope($query, $user);
    }

    /* ------------------------------------------------------------ filters */

    /**
     * @param  Builder<MaintenanceRecord>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        $term = '%'.$this->escape($search).'%';

        $query->where(function (Builder $q) use ($term): void {
            $q->where('maintenance_records.title', 'ILIKE', $term)
                ->orWhere('maintenance_records.diagnosis', 'ILIKE', $term)
                ->orWhere('maintenance_records.resolution', 'ILIKE', $term)
                ->orWhere('maintenance_records.root_cause', 'ILIKE', $term);
        });
    }

    /**
     * @param  Builder<MaintenanceRecord>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyCommonFilters(Builder $query, array $params): void
    {
        if ($this->filled($params['status'] ?? null)) {
            $query->whereIn('maintenance_records.status', $this->list($params['status']));
        }

        if ($this->filled($params['type'] ?? null)) {
            $slugs = $this->list($params['type']);
            $query->whereHas('type', fn (Builder $q) => $q->whereIn('slug', $slugs));
        }

        if (($params['preventive'] ?? null) !== null && $params['preventive'] !== '') {
            $preventive = filter_var($params['preventive'], FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('type', fn (Builder $q) => $q->where('is_preventive', $preventive));
        }

        if ($this->filled($params['pc_unit'] ?? null)) {
            $query->whereIn(
                'maintenance_records.pc_unit_id',
                PcUnit::withTrashed()->select('id')->where('uuid', (string) $params['pc_unit']),
            );
        }

        if ($this->filled($params['asset'] ?? null)) {
            $query->whereIn(
                'maintenance_records.asset_id',
                Asset::withTrashed()->select('id')->where('uuid', (string) $params['asset']),
            );
        }

        if ($this->filled($params['ticket'] ?? null)) {
            $query->whereIn(
                'maintenance_records.ticket_id',
                Ticket::withTrashed()->select('id')->where('uuid', (string) $params['ticket']),
            );
        }

        // A record's location is its target's location — the record itself has
        // no room. Either side of the target CHECK may carry it, so both are
        // consulted rather than assuming a PC.
        if ($this->filled($params['room'] ?? null)) {
            $this->applyRoomFilter(
                $query,
                Room::withTrashed()->select('id')->where('uuid', (string) $params['room']),
            );
        }

        if ($this->filled($params['building'] ?? null)) {
            $this->applyRoomFilter(
                $query,
                Room::withTrashed()->select('id')->whereIn(
                    'floor_id',
                    Floor::withTrashed()->select('id')->whereIn(
                        'building_id',
                        Building::withTrashed()->select('id')->where('uuid', (string) $params['building']),
                    ),
                ),
            );
        }

        if ($this->filled($params['scheduled_from'] ?? null)) {
            $query->whereNotNull('maintenance_records.scheduled_for')
                ->where('maintenance_records.scheduled_for', '>=', (string) $params['scheduled_from']);
        }

        if ($this->filled($params['scheduled_to'] ?? null)) {
            $query->whereNotNull('maintenance_records.scheduled_for')
                ->where('maintenance_records.scheduled_for', '<=', (string) $params['scheduled_to']);
        }

        if (($params['overdue'] ?? null) === true || ($params['overdue'] ?? null) === '1') {
            $query->whereIn('maintenance_records.status', $this->openStatusValues())
                ->whereNotNull('maintenance_records.scheduled_for')
                ->where('maintenance_records.scheduled_for', '<', now());
        }
    }

    /**
     * Filters only the administrative directory offers.
     *
     * `technician` is here rather than in the common set for the same reason it
     * is in Tickets: a technician filtering by technician could only ever be
     * asking about someone else's work, and the scope would refuse it anyway —
     * offering the control would describe something that cannot happen.
     *
     * @param  Builder<MaintenanceRecord>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyAdministrativeFilters(Builder $query, array $params): void
    {
        if ($this->filled($params['technician'] ?? null)) {
            $query->whereIn(
                'maintenance_records.technician_id',
                User::withTrashed()->select('id')->where('uuid', (string) $params['technician']),
            );
        }
    }

    /**
     * Constrain to records whose target — PC unit or standalone asset — sits in
     * one of the given rooms.
     *
     * @param  Builder<MaintenanceRecord>  $query
     * @param  Builder<Room>  $rooms
     */
    private function applyRoomFilter(Builder $query, Builder $rooms): void
    {
        $query->where(function (Builder $q) use ($rooms): void {
            $q->whereIn(
                'maintenance_records.pc_unit_id',
                PcUnit::withTrashed()->select('id')->whereIn('room_id', $rooms->clone()),
            )->orWhereIn(
                'maintenance_records.asset_id',
                Asset::withTrashed()->select('id')->whereIn('current_room_id', $rooms->clone()),
            );
        });
    }

    /**
     * @param  Builder<MaintenanceRecord>  $query
     */
    private function applyTrashed(Builder $query, string $mode): void
    {
        match ($mode) {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => null,
        };
    }

    /* --------------------------------------------------------------- sort */

    /**
     * @param  Builder<MaintenanceRecord>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $column = self::SORTABLE[$sort] ?? 'created_at';

        match ($column) {
            'type' => $this->sortByType($query, $direction),
            'technician' => $this->sortByTechnician($query, $direction),
            default => $query->orderBy("maintenance_records.{$column}", $direction),
        };

        // Stable tiebreak: without it, pagination repeats or drops a row
        // whenever the sorted column holds duplicates.
        $query->orderByDesc('maintenance_records.id');
    }

    /**
     * @param  Builder<MaintenanceRecord>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByType(Builder $query, string $direction): void
    {
        $query->leftJoin('maintenance_types', 'maintenance_types.id', '=', 'maintenance_records.maintenance_type_id')
            ->select('maintenance_records.*')
            ->orderBy('maintenance_types.name', $direction);
    }

    /**
     * @param  Builder<MaintenanceRecord>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByTechnician(Builder $query, string $direction): void
    {
        $query->leftJoin('users as technicians', 'technicians.id', '=', 'maintenance_records.technician_id')
            ->select('maintenance_records.*')
            ->orderBy('technicians.last_name', $direction)
            ->orderBy('technicians.first_name', $direction);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * @return list<string>
     */
    private function openStatusValues(): array
    {
        return array_map(
            static fn (MaintenanceStatus $status): string => $status->value,
            MaintenanceVisibility::OPEN_STATUSES,
        );
    }

    /**
     * Did the caller ask for the whole estate, and may they have it?
     *
     * The parameter alone is never enough — an administrator gets the estate,
     * anyone else gets their own work regardless of what they asked for.
     *
     * @param  array<string, mixed>  $params
     */
    private function wantsEstate(array $params, User $user): bool
    {
        $asked = ($params['scope'] ?? null) === 'all';

        return $asked && $this->visibility->canSeeAdministrative($user);
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

        return $direction === 'asc' ? 'asc' : 'desc';
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 'all';
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        $candidates = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $candidates,
        )));
    }

    /** Escape user wildcards so `%` and `_` match literally. */
    private function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
