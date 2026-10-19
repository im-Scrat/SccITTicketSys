<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Domains\Analytics\Services\TicketMetrics;
use App\Domains\Assets\Services\AssetDirectoryQuery;
use App\Enums\TicketUpdateType;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
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
        $query = $this->reportScope($params, $user)->with([
            // Phase 2.10 — where each row was *reported* (the stored room,
            // archived or not), for the "Reported in" line a report's
            // drill-down list shows beside the PC's current location.
            'reportedRoom' => fn ($q) => $q->withTrashed()->select(['id', 'uuid', 'name', 'floor_id', 'deleted_at']),
            'reportedRoom.floor' => fn ($q) => $q->withTrashed()->select(['id', 'building_id']),
            'reportedRoom.floor.building' => fn ($q) => $q->withTrashed()->select(['id', 'name']),
        ]);

        $this->applySearch($query, $this->term($params));
        $this->applySort(
            $query,
            (string) ($params['sort'] ?? 'created_at'),
            $this->direction($params, 'desc'),
        );

        return $query->paginate($this->perPage($params))->withQueryString();
    }

    /**
     * The administrator directory's population, without search, sort or paging
     * (Phase 2.10, SDD DD-88).
     *
     * Report figures aggregate over exactly this builder, and a figure's
     * drill-down link carries the same parameters into {@see directory()}, which
     * starts from the same call — so a count and the list it opens are one
     * definition and cannot drift (the rule DD-81 set for the SLA tiles).
     *
     * `$reportedBefore` is the one thing a link cannot say: a comparison window
     * cut "at the same point in time" (18 Sep, 04:41). Its figures use it; such a
     * figure gets no drill-down at all rather than an approximate one.
     *
     * @param  array<string, mixed>  $params
     * @return Builder<Ticket>
     */
    public function reportScope(array $params, User $user, ?CarbonImmutable $reportedBefore = null): Builder
    {
        $query = $this->base($user);

        $this->applyTrashed($query, (string) ($params['trashed'] ?? 'without'));
        $this->applyCommonFilters($query, $params);
        $this->applyAdministrativeFilters($query, $params);

        if ($reportedBefore !== null) {
            $query->where('tickets.created_at', '<', $reportedBefore->utc());
        }

        return $query;
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
        $this->applyResolvedWithin($query, $params);
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

        if (($params['scope'] ?? 'active') === 'assigned') {
            /*
             * `scope=assigned` — every ticket whose current owner is this
             * technician, whatever its status or assignment state. This is the
             * population the dashboard's "Open tickets", "Due within 4 h" and
             * "Overdue" figures count (`assigned_technician_id`), so the lists
             * those cards open have to start from the same set. The default
             * (`active`) is the work queue proper: tickets with a live assignment
             * row. The two differ on, for example, a resolved ticket awaiting
             * confirmation, whose assignment can already be completed.
             *
             * `base()` has already applied `TicketVisibility`, so this narrows
             * what the technician may read and can never widen it: a ticket they
             * hold no readable assignment on is absent from every view, and a
             * guessed uuid is refused by `TicketPolicy` for the same reason.
             */
            $query->where('tickets.assigned_technician_id', $user->getKey());
        } else {
            $this->visibility->scopeAssigned($query, $user, TicketVisibility::WRITABLE_ASSIGNMENT_STATUSES);
        }

        $this->applySearch($query, $this->term($params));
        $this->applyCommonFilters($query, $params);
        $this->applyPostureFilters($query, $params, dueSoon: true);

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
            'reporter:id,uuid,first_name,middle_name,last_name',
            'assignedTechnician:id,uuid,first_name,middle_name,last_name',
            'pcUnit:id,uuid,unit_code,pc_name,room_id',
            'pcUnit.room:id,uuid,name,floor_id',
            'pcUnit.room.floor:id,uuid,name,floor_number,building_id',
            'pcUnit.room.floor.building:id,uuid,name,code',
            // Phase 2.9 (SDD DD-79). The model's name is what
            // `Asset::displayName()` falls back to when the asset has none.
            // Read with trashed, as the report form's room lookup reads it
            // (`AssetOptions::roomAssets()`): an archived catalog entry must
            // not turn the equipment it names into its tag on the ticket
            // (DD-78), when the reporter picked it by its model name.
            'asset:id,uuid,asset_tag,name,hardware_model_id',
            'asset.hardwareModel' => fn ($query) => $query->withTrashed()->select(['id', 'model_name']),
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
     * "Resolved within the last N days" — the requester's own list only.
     *
     * This is the predicate {@see TicketMetrics::reporterSummary()} counts as
     * `resolved_recently` — `resolved_at >= now() - N days`, same operator, same
     * clock — so the dashboard figure and the list it links to are one definition
     * and cannot drift apart. Reopening nulls `resolved_at`, so a ticket that is
     * open again is not "resolved within" anything.
     *
     * Called from {@see own()} alone and kept out of {@see applyCommonFilters()}
     * on purpose: the community feed, the administrator directory and the
     * technician queue gain no new filter surface from this.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyResolvedWithin(Builder $query, array $params): void
    {
        if (! $this->filled($params['resolved_within'] ?? null)) {
            return;
        }

        $query->where('tickets.resolved_at', '>=', now()->subDays((int) $params['resolved_within']));
    }

    /**
     * `breached` (shown as **Overdue**) and `due_soon` — the SLA lists behind the
     * dashboard cards and the Ticket Management tiles.
     *
     * Both are `SlaCalculator`'s predicates, the same ones `TicketMetrics` counts
     * with, so a tile's figure and the list it opens cannot disagree. (The
     * directory's `breached` used to test the resolution deadline alone while its
     * tile counted response misses too.) Offered by the administrator directory
     * (`breached`) and the technician queue (both); a requester has no business
     * filtering by SLA posture.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyPostureFilters(Builder $query, array $params, bool $dueSoon): void
    {
        if ($this->flag($params['breached'] ?? null)) {
            SlaCalculator::constrainBreached(SlaCalculator::constrainLive($query));
        }

        if ($dueSoon && $this->flag($params['due_soon'] ?? null)) {
            SlaCalculator::constrainDueWithin(SlaCalculator::constrainLive($query));
        }
    }

    /** A validated boolean query flag: `true` or `'1'` (the only truthy forms the request lets through). */
    private function flag(mixed $value): bool
    {
        return $value === true || $value === '1' || $value === 1;
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

        $this->applyPostureFilters($query, $params, dueSoon: false);

        if (($params['awaiting_confirmation'] ?? null) === true || ($params['awaiting_confirmation'] ?? null) === '1') {
            $query->whereHas('status', fn (Builder $q) => $q->where('slug', 'resolved'));
        }

        if (($params['escalated'] ?? null) === true || ($params['escalated'] ?? null) === '1') {
            $this->onlyEscalated($query);
        }

        $this->applyReportFilters($query, $params);
    }

    /**
     * Phase 2.10 — the filters report drill-downs carry (SDD DD-88).
     *
     * Administrative directory only, like the rest of this group: a requester's
     * list and a technician's queue gain no new filter surface.
     *
     *  - `reported_from` / `reported_to`: school-local dates, inclusive, turned
     *    into a half-open UTC range — the same boundary rule the reports use.
     *  - `room_type`, `without_room`: the **stored** room (where the ticket was
     *    reported), archived rooms included, as the room filter already does.
     *  - `problem_area`: the category's problem area (owner decision D6).
     *  - `asset`: tickets about one non-PC item (archived items included).
     *  - `exclude_duplicates`, `exclude_cancelled`: together they are what the
     *    reports count as *problems*. A duplicate is a second report of one
     *    fault and a cancelled ticket was withdrawn; ranking rooms by either
     *    would rank reporting habits, not faults.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyReportFilters(Builder $query, array $params): void
    {
        if ($this->filled($params['reported_from'] ?? null)) {
            $query->where('tickets.created_at', '>=', SchoolClock::startOfLocalDay((string) $params['reported_from'])->utc());
        }

        if ($this->filled($params['reported_to'] ?? null)) {
            $query->where('tickets.created_at', '<', SchoolClock::startOfLocalDay((string) $params['reported_to'])->addDay()->utc());
        }

        if ($this->filled($params['room_type'] ?? null)) {
            $query->whereIn(
                'tickets.room_id',
                Room::withTrashed()->select('id')->whereIn('room_type', $this->list($params['room_type'])),
            );
        }

        if ($this->flag($params['without_room'] ?? null)) {
            $query->whereNull('tickets.room_id');
        }

        if ($this->filled($params['problem_area'] ?? null)) {
            $areas = $this->list($params['problem_area']);
            $query->whereHas('category', fn (Builder $q) => $q->whereIn('problem_area', $areas));
        }

        if ($this->filled($params['asset'] ?? null)) {
            $query->whereIn(
                'tickets.asset_id',
                Asset::withTrashed()->select('id')->where('uuid', (string) $params['asset']),
            );
        }

        if ($this->flag($params['exclude_duplicates'] ?? null)) {
            $query->whereNull('tickets.duplicate_of_id');
        }

        if ($this->flag($params['exclude_cancelled'] ?? null)) {
            $query->whereHas('status', fn (Builder $q) => $q->where('slug', '!=', 'cancelled'));
        }
    }

    /**
     * Phase 2.9 — open tickets whose reporter said NOT FIXED after the AI's
     * suggestions and that still wait for an owner (WP-J → admin triage).
     *
     * The same rule as {@see ReporterOutcome::current()}, expressed as SQL so it
     * can filter and paginate: the reporter's own `ai_analysis` row with outcome
     * `not_fixed`, newer than the latest move *into* `open` (a reopen starts a
     * new period and forgets the old answer). No column stores this — the
     * timeline is the record — so nothing can drift out of step with it.
     *
     * @param  Builder<Ticket>  $query
     */
    private function onlyEscalated(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('slug', 'open'))
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('ticket_updates as tu')
                    ->whereColumn('tu.ticket_id', 'tickets.id')
                    ->whereColumn('tu.user_id', 'tickets.reporter_id')
                    ->where('tu.update_type', TicketUpdateType::AiAnalysis->value)
                    ->whereRaw("tu.metadata->>'outcome' = ?", [ReporterOutcome::NOT_FIXED])
                    ->whereRaw(
                        "tu.id > COALESCE((SELECT max(su.id) FROM ticket_updates su WHERE su.ticket_id = tickets.id AND su.update_type = ? AND su.metadata->>'to' = 'open'), 0)",
                        [TicketUpdateType::StatusChange->value],
                    );
            });
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
