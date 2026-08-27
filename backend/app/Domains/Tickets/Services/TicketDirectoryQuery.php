<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Domains\Assets\Services\AssetDirectoryQuery;
use App\Models\Building;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Server-side ticket queries (SRS FR-TKT-014).
 *
 * Modelled on {@see AssetDirectoryQuery} — fixed sort allow-lists, escaped
 * search terms, everything resolved in the database — with two differences that
 * matter:
 *
 *  1. **Every entry point applies {@see TicketVisibility} first.** The scope is
 *     not something a caller opts into; it is the first thing each builder does,
 *     so there is no code path that produces an unscoped ticket list.
 *  2. **Search uses the generated `tsvector`**, not `ILIKE`. `tickets` already
 *     carries a stored `search_vector` over title + description with a GIN
 *     index, so full-text ranking is free and a `%term%` scan would be strictly
 *     worse.
 *
 * Four entry points, because the four reading modes genuinely differ:
 * {@see feed()} is a **cursor**-paginated card stream (offset paging shifts rows
 * under a reader when tickets arrive mid-scroll), while {@see directory()},
 * {@see queue()} and {@see history()} are **offset**-paginated tables with page
 * numbers.
 */
class TicketDirectoryQuery
{
    /** Public sort key => physical column, or a join sentinel resolved below. */
    private const SORTABLE = [
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'ticket_number' => 'ticket_number',
        'title' => 'title',
        'upvotes' => 'upvote_count',
        'comments' => 'comment_count',
        'status' => 'status',
        'priority' => 'priority',
        'category' => 'category',
        'reporter' => 'reporter',
        'technician' => 'technician',
        'resolution_due_at' => 'resolution_due_at',
    ];

    /** The feed offers a deliberately narrower set — it is a reading surface. */
    private const FEED_SORTABLE = ['recent', 'upvotes', 'comments', 'priority'];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function __construct(private readonly TicketVisibility $visibility) {}

    /**
     * The requester community feed — cursor-paginated (SRS UCS-02).
     *
     * @param  array<string, mixed>  $params
     * @return CursorPaginator<int, Ticket>
     */
    public function feed(array $params, User $user): CursorPaginator
    {
        $query = $this->base($user)
            // Terminal tickets leave the feed: a community browsing for
            // duplicates wants live problems, not an archive.
            ->when(
                ($params['include_closed'] ?? false) !== true,
                fn (Builder $q): Builder => $q->whereHas('status', fn (Builder $s) => $s->where('is_open', true)),
            );

        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);

        if (($params['mine'] ?? null) === true || ($params['mine'] ?? null) === '1') {
            $this->visibility->scopeOwn($query, $user);
        }

        $this->applyFeedSort($query, (string) ($params['sort'] ?? 'recent'));

        return $query->cursorPaginate($this->perPage($params))->withQueryString();
    }

    /**
     * The administrator directory — offset-paginated.
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function directory(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);
        $this->applyAdministrativeFilters($query, $params);
        $this->applySort(
            $query,
            (string) ($params['sort'] ?? 'created_at'),
            $this->direction($params, 'desc'),
        );

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * A requester's own tickets — offset-paginated.
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function own(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);
        $this->visibility->scopeOwn($query, $user);

        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);
        $this->applySort(
            $query,
            (string) ($params['sort'] ?? 'created_at'),
            $this->direction($params, 'desc'),
        );

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * A technician's **active** work queue, ordered the way a queue should be:
     * most severe first, then the soonest deadline (FR-ASN-006).
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function queue(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);
        $this->visibility->scopeAssigned($query, $user, TicketVisibility::WRITABLE_ASSIGNMENT_STATUSES);

        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);

        $query->leftJoin('ticket_priorities', 'ticket_priorities.id', '=', 'tickets.priority_id')
            ->select('tickets.*')
            ->orderByDesc('ticket_priorities.level')
            ->orderByRaw('tickets.resolution_due_at IS NULL, tickets.resolution_due_at ASC')
            ->orderBy('tickets.id');

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * A technician's finished work — read-only history (SDD DD-42).
     *
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function history(array $params, User $user): LengthAwarePaginator
    {
        $query = $this->base($user);

        // Everything readable minus everything still writable: what is left is
        // work this technician has finished or handed on.
        $this->visibility->scopeAssigned($query, $user);
        $query->whereNotIn(
            'tickets.id',
            TechnicianAssignment::query()
                ->select('ticket_id')
                ->where('technician_id', $user->getKey())
                ->whereIn('status', TicketVisibility::WRITABLE_ASSIGNMENT_STATUSES),
        );

        $this->applySearch($query, $this->term($params));
        $this->applySort($query, 'updated_at', 'desc');

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /* --------------------------------------------------------------- base */

    /**
     * Every query starts here: visibility-scoped and fully eager-loaded.
     *
     * The eager loads are not optional decoration — a ticket list renders its
     * status, priority, category, reporter and location on every row, so without
     * them a 20-row page is 100+ queries.
     *
     * @return Builder<Ticket>
     */
    private function base(User $user): Builder
    {
        $query = Ticket::query()->with([
            'status',
            'priority',
            'category',
            'reporter:id,uuid,first_name,last_name',
            'assignedTechnician:id,uuid,first_name,last_name',
            'pcUnit:id,uuid,unit_code,pc_name,room_id',
            'pcUnit.room:id,uuid,name,floor_id',
            'pcUnit.room.floor:id,uuid,name,floor_number,building_id',
            'pcUnit.room.floor.building:id,uuid,name,code',
            'room:id,uuid,name,floor_id',
            'room.floor:id,uuid,name,floor_number,building_id',
            'room.floor.building:id,uuid,name,code',
        ]);

        return $this->visibility->scope($query, $user);
    }

    /* ------------------------------------------------------------ filters */

    /**
     * Full-text search over title + description, using the stored `tsvector`
     * and its GIN index rather than an unanchored `ILIKE` scan.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = $search !== null ? trim($search) : '';

        if ($search === '') {
            return;
        }

        // `plainto_tsquery` treats the input as plain words, so a user typing
        // `&` or `:` gets a search rather than a syntax error.
        $query->where(function (Builder $q) use ($search): void {
            $q->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$search])
                // Ticket numbers are not in the tsvector and are exactly what
                // someone pastes from an email, so match them directly.
                ->orWhere('tickets.ticket_number', 'ILIKE', '%'.$this->escape($search).'%');
        });
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyCommonFilters(Builder $query, array $params): void
    {
        if ($this->filled($params['status'] ?? null)) {
            $slugs = $this->list($params['status']);
            $query->whereHas('status', fn (Builder $q) => $q->whereIn('slug', $slugs));
        }

        if ($this->filled($params['priority'] ?? null)) {
            $slugs = $this->list($params['priority']);
            $query->whereHas('priority', fn (Builder $q) => $q->whereIn('slug', $slugs));
        }

        if ($this->filled($params['category'] ?? null)) {
            $slugs = $this->list($params['category']);
            $query->whereHas('category', fn (Builder $q) => $q->whereIn('slug', $slugs));
        }

        if ($this->filled($params['room'] ?? null)) {
            $query->whereIn(
                'tickets.room_id',
                Room::withTrashed()->select('id')->where('uuid', (string) $params['room']),
            );
        }

        if ($this->filled($params['building'] ?? null)) {
            $query->whereIn(
                'tickets.room_id',
                Room::withTrashed()->select('id')->whereIn(
                    'floor_id',
                    Floor::withTrashed()->select('id')->whereIn(
                        'building_id',
                        Building::withTrashed()->select('id')->where('uuid', (string) $params['building']),
                    ),
                ),
            );
        }

        if (($params['has_pc_unit'] ?? null) === true || ($params['has_pc_unit'] ?? null) === '1') {
            $query->whereNotNull('tickets.pc_unit_id');
        }

        if ($this->filled($params['pc_unit'] ?? null)) {
            $query->whereIn(
                'tickets.pc_unit_id',
                PcUnit::withTrashed()->select('id')->where('uuid', (string) $params['pc_unit']),
            );
        }
    }

    /**
     * Filters only the administrative directory offers — a requester has no
     * business filtering by technician or SLA posture.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyAdministrativeFilters(Builder $query, array $params): void
    {
        $technician = $params['technician'] ?? null;

        if ($technician === 'unassigned') {
            $query->whereNull('tickets.assigned_technician_id');
        } elseif ($this->filled($technician)) {
            $query->whereIn(
                'tickets.assigned_technician_id',
                User::withTrashed()->select('id')->where('uuid', (string) $technician),
            );
        }

        if (($params['breached'] ?? null) === true || ($params['breached'] ?? null) === '1') {
            $query->whereHas('status', fn (Builder $q) => $q->where('is_terminal', false))
                ->whereNotNull('tickets.resolution_due_at')
                ->where('tickets.resolution_due_at', '<', now());
        }

        if (($params['awaiting_confirmation'] ?? null) === true || ($params['awaiting_confirmation'] ?? null) === '1') {
            $query->whereHas('status', fn (Builder $q) => $q->where('slug', 'resolved'));
        }
    }

    /**
     * @param  Builder<Ticket>  $query
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
     * @param  Builder<Ticket>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $column = self::SORTABLE[$sort] ?? 'created_at';

        match ($column) {
            'status' => $this->sortByRelated($query, 'ticket_statuses', 'current_status_id', 'sort_order', $direction),
            'priority' => $this->sortByRelated($query, 'ticket_priorities', 'priority_id', 'level', $direction),
            'category' => $this->sortByRelated($query, 'ticket_categories', 'category_id', 'name', $direction),
            'reporter' => $this->sortByUser($query, 'reporter_id', $direction),
            'technician' => $this->sortByUser($query, 'assigned_technician_id', $direction),
            default => $query->orderBy("tickets.{$column}", $direction),
        };

        // Stable tiebreak: without it, pagination can repeat or drop a row when
        // the sorted column holds duplicates.
        $query->orderByDesc('tickets.id');
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyFeedSort(Builder $query, string $sort): void
    {
        $sort = in_array($sort, self::FEED_SORTABLE, true) ? $sort : 'recent';

        match ($sort) {
            'upvotes' => $query->orderByDesc('tickets.upvote_count'),
            'comments' => $query->orderByDesc('tickets.comment_count'),
            'priority' => $this->sortByRelated($query, 'ticket_priorities', 'priority_id', 'level', 'desc'),
            default => null,
        };

        // The cursor is (created_at, id), so both must be in the order clause
        // for cursor pagination to be stable.
        $query->orderByDesc('tickets.created_at')->orderByDesc('tickets.id');
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByRelated(
        Builder $query,
        string $table,
        string $foreignKey,
        string $orderColumn,
        string $direction,
    ): void {
        $query->leftJoin($table, "{$table}.id", '=', "tickets.{$foreignKey}")
            ->select('tickets.*')
            ->orderBy("{$table}.{$orderColumn}", $direction);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function sortByUser(Builder $query, string $foreignKey, string $direction): void
    {
        $alias = $foreignKey === 'reporter_id' ? 'reporters' : 'technicians';

        $query->leftJoin("users as {$alias}", "{$alias}.id", '=', "tickets.{$foreignKey}")
            ->select('tickets.*')
            ->orderBy("{$alias}.last_name", $direction)
            ->orderBy("{$alias}.first_name", $direction);
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

    /** Escape user wildcards so `%`/`_` match literally. */
    private function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
