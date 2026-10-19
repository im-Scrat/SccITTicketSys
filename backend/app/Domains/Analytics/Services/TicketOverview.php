<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportBucket;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportPeriod;
use App\Enums\ProblemArea;
use App\Enums\ReportComparison;
use App\Enums\ReportPeriodPreset;
use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Admin Dashboard's ticket overview (Phase 2.10, SDD DD-88): KPIs, status
 * distribution, the series with its comparison, where problems were reported,
 * and the rule-based summary — one payload, one population.
 *
 * **One population.** Every figure counts the tickets *reported* in the period,
 * by their current status, through `TicketDirectoryQuery::reportScope()`. The
 * KPIs, the donut, the line and the ranking therefore always reconcile, and
 * each drill-down list totals exactly to its figure. Two deliberate refinements
 * are named on screen: rooms are ranked by *problems* (no duplicates, nothing
 * cancelled), and "resolved in period" is a lifecycle event count
 * (`TicketLifecycleMetrics`) that ignores the report date.
 *
 * **Cache.** 30 seconds (DR-019, FR-DSH-005), keyed on the dashboard version
 * (so a realtime signal forces a fresh build), on the absolute instants the
 * filters resolved to, and on the module data the viewer may see. Administrators
 * share entries — every administrator reads every ticket — and access is
 * asserted before the cache is read, so no other caller can reach one.
 */
final class TicketOverview
{
    private const TTL_SECONDS = 30;

    private const RANKING_LIMIT = 100;

    public function __construct(
        private readonly ReportsAccess $access,
        private readonly TicketOverviewQuery $query,
        private readonly TicketLifecycleMetrics $lifecycle,
        private readonly AdminDashboardSignal $signal,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     *
     * @throws AuthorizationException
     */
    public function build(ReportFilters $filters, User $actor): array
    {
        if (! $this->access->canViewTickets($actor)) {
            throw new AuthorizationException('The ticket overview is available to administrators who can view tickets.');
        }

        $domains = $this->access->visibleDomains($actor);
        $key = sprintf(
            'analytics:ticket-overview:v%d:%s',
            $this->signal->version(),
            sha1(json_encode([$filters->cacheKeyParts(), $domains], JSON_THROW_ON_ERROR)),
        );

        /** @var array{data: array<string, mixed>, meta: array<string, mixed>} */
        return Cache::remember(
            $key,
            self::TTL_SECONDS,
            fn (): array => $this->compose($filters, $actor, in_array(ReportsAccess::DOMAIN_LOCATIONS, $domains, true)),
        );
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function compose(ReportFilters $filters, User $actor, bool $withLocations): array
    {
        $period = $filters->period;
        $unit = $filters->granularity;
        $recordsStart = $this->recordsStart();

        $scope = $this->query->scope($filters->directoryParams(), $actor);
        $totals = $this->query->totals($scope);
        $statusCounts = $this->query->statusCounts($scope);
        $counts = $this->query->bucketCounts($scope, $unit);
        $buckets = $period->buckets($unit, $recordsStart);

        $comparison = $this->comparison($filters, $actor, $recordsStart);
        $resolved = $this->lifecycle->resolvedInPeriod($filters, $period, $actor);
        $locations = $withLocations ? $this->locations($filters, $actor) : null;

        $statuses = DB::table('ticket_statuses')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'slug', 'name', 'is_open', 'is_terminal']);
        $shownStatuses = $filters->hasStatusFilter()
            ? $statuses->filter(fn (object $s): bool => in_array($s->slug, $filters->statuses, true))->values()
            : $statuses;
        $openSlugs = $statuses->filter(fn (object $s): bool => (bool) $s->is_open)->pluck('slug')->all();

        $compared = $comparison['state'] === 'on';
        $previous = $compared ? $comparison['total'] : null;
        $change = ReportMetrics::change($totals['total'], $previous);

        if ($comparison['partial']) {
            $change['change_pct'] = null;
        }

        $statusRows = $shownStatuses->map(fn (object $s): array => [
            'slug' => (string) $s->slug,
            'label' => (string) $s->name,
            'count' => $statusCounts[(int) $s->id] ?? 0,
            'is_open' => (bool) $s->is_open,
            'is_terminal' => (bool) $s->is_terminal,
        ])->values()->all();
        $percentages = ReportMetrics::largestRemainder(array_column($statusRows, 'count'));

        foreach ($statusRows as $i => $row) {
            $statusRows[$i]['pct'] = $percentages[$i];
            $statusRows[$i]['drilldown'] = TicketDrilldown::for($filters, ['status' => $row['slug']]);
        }

        $stillOpen = $filters->hasStatusFilter() ? array_values(array_intersect($openSlugs, $filters->statuses)) : $openSlugs;
        sort($stillOpen);

        $facts = [
            'period_label' => ReportPeriod::rangeText($period->start, $period->end),
            'comparison_label' => $filters->comparison !== null ? ReportPeriod::rangeText($filters->comparison->start, $filters->comparison->end) : null,
            'comparison' => $comparison['state'],
            'comparison_partial' => $comparison['partial'],
            'records_start' => $recordsStart?->format('j M Y'),
            'starts_before_records' => $recordsStart !== null && $period->start->lessThan($recordsStart->startOfDay()),
            'reported' => $totals['total'],
            'previous' => $previous,
            'days' => $period->days(),
            'previous_days' => $compared ? $filters->comparison?->days() : null,
            'unit' => $unit,
            'buckets' => $buckets,
            'counts' => $counts,
            'open' => $totals['open'],
            'awaiting' => $totals['awaiting_confirmation'],
            'duplicates' => $totals['duplicates'],
            'cancelled' => $totals['cancelled'],
            'without_history' => $totals['without_history'],
            'locations' => $locations !== null ? $locations['facts'] : null,
        ];

        return [
            'data' => [
                'kpis' => [
                    'reported' => [
                        'value' => $totals['total'],
                        'comparison' => $previous,
                        ...$change,
                        'duplicates' => $totals['duplicates'],
                        'cancelled' => $totals['cancelled'],
                        'drilldown' => TicketDrilldown::for($filters),
                    ],
                    'daily_average' => $period->days() > 1 ? [
                        'value' => ReportMetrics::perDay($totals['total'], $period->days()),
                        'comparison' => $compared && ! $comparison['partial'] && $filters->comparison !== null
                            ? ReportMetrics::perDay((int) $previous, $filters->comparison->days())
                            : null,
                        'days' => $period->days(),
                        'comparison_days' => $compared ? $filters->comparison?->days() : null,
                    ] : null,
                    'open_now' => [
                        'value' => $totals['open'],
                        'share_pct' => ReportMetrics::share($totals['open'], $totals['total']),
                        'awaiting_confirmation' => $totals['awaiting_confirmation'],
                        'drilldown' => $stillOpen !== [] ? TicketDrilldown::for($filters, ['status' => implode(',', $stillOpen)]) : null,
                    ],
                    'resolved_in_period' => [
                        'value' => $resolved,
                        'drilldown' => null,
                    ],
                ],
                'status_distribution' => [
                    'total' => $totals['total'],
                    'rows' => $statusRows,
                ],
                'series' => [
                    'granularity' => $unit->value,
                    'unit_label' => $unit->noun(),
                    'buckets' => array_map(fn (ReportBucket $b): array => $b->toArray(
                        $counts[$b->key] ?? 0,
                        TicketDrilldown::forBucket($filters, $period, $b->start, $b->end),
                    ), $buckets),
                    'comparison' => $compared ? $this->comparisonSeries($filters, $comparison, count($buckets)) : null,
                    'stats' => $this->seriesStats($buckets, $counts, $totals['total'], $previous),
                ],
                'locations' => $locations !== null ? $locations['data'] : null,
                'summary' => TicketOverviewSummary::build($facts),
            ],
            'meta' => [
                'report_type' => 'ticket_overview',
                'timezone' => SchoolClock::TIMEZONE,
                'timezone_label' => sprintf('%s (UTC%s)', SchoolClock::TIMEZONE, $period->asOf->format('P')),
                'generated_at' => now()->toIso8601ZuluString(),
                'as_of' => $period->asOf->utc()->toIso8601ZuluString(),
                'as_of_label' => 'Data as of '.$period->asOf->subMinute()->format('j M Y, H:i').' ('.SchoolClock::TIMEZONE.')',
                'today' => $period->asOf->subSecond()->format('Y-m-d'),
                'period' => $period->toArray(),
                'comparison' => $filters->comparison !== null ? [
                    ...$filters->comparison->toArray(),
                    'mode' => $filters->compareMode->value,
                    'description' => $this->comparisonDescription($filters),
                    'available' => $compared,
                    'reason' => $compared ? ($comparison['partial'] ? 'partial_records' : null) : $comparison['state'],
                ] : null,
                'granularity' => [
                    'requested' => $filters->requestedGranularity->value,
                    'resolved' => $unit->value,
                    'max_buckets' => $unit->maxBuckets(),
                ],
                'filters' => $this->describe($filters, $statuses->all()),
                'query' => $filters->toQuery(),
                'data_starts_at' => $recordsStart?->format('Y-m-d'),
                'data_quality' => ['tickets_without_history' => $totals['without_history']],
                'definitions' => ReportMetrics::ticketDefinitions(),
                'visible' => ['locations' => $withLocations],
            ],
        ];
    }

    /**
     * The comparison window's figures, and whether they may be shown at all.
     *
     * @return array{state: 'off'|'on'|'status_filter'|'before_records', partial: bool, total: int, counts: array<string, int>, buckets: list<ReportBucket>}
     */
    private function comparison(ReportFilters $filters, User $actor, ?CarbonImmutable $recordsStart): array
    {
        $window = $filters->comparison;
        $none = ['partial' => false, 'total' => 0, 'counts' => [], 'buckets' => []];

        if ($window === null) {
            return ['state' => 'off', ...$none];
        }

        if ($filters->hasStatusFilter()) {
            return ['state' => 'status_filter', ...$none];
        }

        if ($recordsStart !== null && $window->end->lessThanOrEqualTo($recordsStart)) {
            return ['state' => 'before_records', ...$none];
        }

        $scope = $this->query->scope(
            $filters->directoryParams($window),
            $actor,
            $window->expressibleAsDates() ? null : $window->end,
        );

        return [
            'state' => 'on',
            'partial' => $recordsStart !== null && $window->start->lessThan($recordsStart),
            'total' => (clone $scope)->count(),
            'counts' => $this->query->bucketCounts($scope, $filters->granularity),
            'buckets' => $window->buckets($filters->granularity, $recordsStart),
        ];
    }

    /**
     * Aligned by position: comparison bucket i sits under primary bucket i.
     * Comparison buckets past the primary series (31 Jan against a 28-day
     * February) are counted in the total and reported as unplotted.
     *
     * @param  array{state: string, partial: bool, total: int, counts: array<string, int>, buckets: list<ReportBucket>}  $comparison
     * @return array<string, mixed>
     */
    private function comparisonSeries(ReportFilters $filters, array $comparison, int $length): array
    {
        $window = $filters->comparison;
        $plotted = array_slice($comparison['buckets'], 0, $length);
        $unplotted = array_slice($comparison['buckets'], $length);

        return [
            'buckets' => array_map(fn (ReportBucket $b): array => $b->toArray(
                $comparison['counts'][$b->key] ?? 0,
                $window !== null ? TicketDrilldown::forBucket($filters, $window, $b->start, $b->end) : null,
            ), $plotted),
            'unplotted' => [
                'buckets' => count($unplotted),
                'count' => array_sum(array_map(fn (ReportBucket $b): int => $comparison['counts'][$b->key] ?? 0, $unplotted)),
            ],
        ];
    }

    /**
     * @param  list<ReportBucket>  $buckets
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function seriesStats(array $buckets, array $counts, int $total, ?int $previous): array
    {
        $complete = array_values(array_filter($buckets, fn (ReportBucket $b): bool => $b->isComplete()));
        $max = $complete === [] ? 0 : max(array_map(fn (ReportBucket $b): int => $counts[$b->key] ?? 0, $complete));
        $recorded = array_values(array_filter($buckets, fn (ReportBucket $b): bool => ! $b->beforeRecords));

        return [
            'total' => $total,
            'comparison_total' => $previous,
            'peak' => $max > 0 ? [
                'count' => $max,
                'keys' => array_values(array_map(
                    fn (ReportBucket $b): string => $b->key,
                    array_filter($complete, fn (ReportBucket $b): bool => ($counts[$b->key] ?? 0) === $max),
                )),
            ] : null,
            'empty_buckets' => count(array_filter($recorded, fn (ReportBucket $b): bool => ($counts[$b->key] ?? 0) === 0)),
        ];
    }

    /**
     * Where problems were reported: the room ranking and the room-type groups,
     * over problems only (no duplicates, nothing cancelled).
     *
     * @return array{data: array<string, mixed>, facts: array{top: list<array{label: string, count: int}>, located: int, all_scope: bool, types: array<string, array{count: int, per_room: float|null}>, unrecorded: int}}
     */
    private function locations(ReportFilters $filters, User $actor): array
    {
        $problems = ['exclude_duplicates' => '1', 'exclude_cancelled' => '1'];
        $scope = $this->query->scope($filters->directoryParams(null, $problems), $actor);
        $rows = $this->query->roomRows($scope, self::RANKING_LIMIT + 1);
        $types = $this->query->typeCounts($scope);

        $located = 0;
        $roomsWithProblems = 0;

        foreach ($types as $type => $row) {
            if ($type !== 'unspecified') {
                $located += $row['count'];
                $roomsWithProblems += $row['rooms'];
            }
        }

        $shown = array_slice($rows, 0, self::RANKING_LIMIT);
        $ranking = [];
        $rank = 0;
        $previousCount = null;

        foreach ($shown as $position => $row) {
            $count = (int) $row->n;
            $rank = $count === $previousCount ? $rank : $position + 1;
            $previousCount = $count;
            $type = RoomType::tryFrom((string) $row->room_type) ?? RoomType::Other;

            $ranking[] = [
                'rank' => $rank,
                'count' => $count,
                'open' => (int) $row->open_n,
                'share_pct' => ReportMetrics::share($count, $located),
                'room' => [
                    'id' => (string) $row->room_uuid,
                    'name' => (string) $row->room_name,
                    'code' => (string) $row->room_code,
                    'room_type' => $type->value,
                    'room_type_label' => $type->label(),
                    'label' => $this->roomLabel($row),
                    'building' => $row->building_uuid !== null ? ['id' => (string) $row->building_uuid, 'name' => (string) $row->building_name] : null,
                    'archived' => $row->room_deleted_at !== null,
                    'inactive' => ! (bool) $row->room_active,
                ],
                'drilldown' => TicketDrilldown::for($filters, [...$problems, 'room' => (string) $row->room_uuid, 'building' => null, 'room_type' => null]),
            ];
        }

        $shownCount = array_sum(array_column($ranking, 'count'));
        $existing = $filters->room !== null
            ? [$this->roomTypeGroup($filters->room) => 1]
            : $this->query->roomsExisting($filters->period, $filters->building);
        $allScope = ! $filters->hasLocationFilter();

        $groups = [
            'laboratory' => ['Laboratories', ['room_type' => RoomType::Laboratory->value]],
            'office' => ['Offices', ['room_type' => RoomType::Office->value]],
            'other' => ['Other room types', ['room_type' => implode(',', array_map(fn (RoomType $t): string => $t->value, TicketOverviewQuery::OTHER_ROOM_TYPES))]],
            'unspecified' => ['No room recorded', ['without_room' => '1']],
        ];

        $byType = [];
        $typeFacts = [];

        foreach ($groups as $type => [$label, $override]) {
            $count = $types[$type]['count'] ?? 0;

            if (! $allScope && $count === 0 && $filters->roomType !== $type) {
                continue;
            }

            $roomsTotal = $type === 'unspecified' ? null : ($existing[$type] ?? 0);
            $perRoom = $roomsTotal !== null && $roomsTotal > 0 ? round($count / $roomsTotal, 1, PHP_ROUND_HALF_UP) : null;
            $typeFacts[$type] = ['count' => $count, 'per_room' => $perRoom];

            $byType[] = [
                'type' => $type,
                'label' => $label,
                'count' => $count,
                'rooms_with_problems' => $type === 'unspecified' ? null : ($types[$type]['rooms'] ?? 0),
                'rooms_total' => $roomsTotal,
                'per_room' => $perRoom,
                'drilldown' => $count > 0 ? TicketDrilldown::for($filters, [...$problems, ...$override]) : null,
            ];
        }

        $top = [];

        foreach ($ranking as $row) {
            if ($row['rank'] === 1) {
                $top[] = ['label' => $row['room']['label'], 'count' => $row['count']];
            }
        }

        return [
            'data' => [
                'problems_total' => $located + ($types['unspecified']['count'] ?? 0),
                'located' => $located,
                'by_type' => $byType,
                'ranking' => $ranking,
                'rooms_with_problems' => $roomsWithProblems,
                'others' => count($rows) > self::RANKING_LIMIT
                    ? ['rooms' => $roomsWithProblems - count($ranking), 'count' => $located - $shownCount]
                    : null,
            ],
            'facts' => [
                'top' => $top,
                'located' => $located,
                'all_scope' => $allScope,
                'types' => $typeFacts,
                'unrecorded' => $types['unspecified']['count'] ?? 0,
            ],
        ];
    }

    /** "Main Building · Ground floor · Lab 2", the label the location pickers use. */
    private function roomLabel(object $row): string
    {
        $floor = $row->floor_name !== null
            ? ((string) $row->floor_name !== '' ? (string) $row->floor_name : 'Floor '.$row->floor_number)
            : null;

        return implode(' · ', array_values(array_filter([
            $row->building_name !== null ? (string) $row->building_name : null,
            $floor,
            (string) $row->room_name,
        ])));
    }

    private function roomTypeGroup(string $roomUuid): string
    {
        $type = Room::withTrashed()->where('uuid', $roomUuid)->value('room_type');
        $value = $type instanceof RoomType ? $type->value : (string) $type;

        return in_array($value, [RoomType::Laboratory->value, RoomType::Office->value], true) ? $value : 'other';
    }

    private function comparisonDescription(ReportFilters $filters): string
    {
        $window = $filters->comparison;

        if ($window === null) {
            return '';
        }

        return match ($window->shift) {
            'previous_unit' => 'the previous '.match ($filters->period->preset) {
                ReportPeriodPreset::Day => 'day',
                ReportPeriodPreset::Week => 'week',
                ReportPeriodPreset::Month => 'month',
                default => 'year',
            },
            'previous_days' => sprintf('the previous %d days', $filters->period->naturalDays()),
            'weeks_52' => $filters->period->preset === ReportPeriodPreset::Day
                ? 'the same weekday 52 weeks earlier'
                : 'the same weeks last year ('.$window->isoWeekLabel().')',
            default => $filters->compareMode === ReportComparison::LastYear ? 'the same period last year' : 'the comparison period',
        };
    }

    /**
     * Labels for the filters in effect, for the header, the chips and exports.
     *
     * @param  list<object>  $statuses
     * @return array<string, mixed>
     */
    private function describe(ReportFilters $filters, array $statuses): array
    {
        $room = $filters->room !== null
            ? Room::withTrashed()->with(['floor' => fn ($q) => $q->withTrashed(), 'floor.building' => fn ($q) => $q->withTrashed()])
                ->where('uuid', $filters->room)->first()
            : null;
        $building = $filters->building !== null ? Building::withTrashed()->where('uuid', $filters->building)->first() : null;
        $statusLabels = collect($statuses)->pluck('name', 'slug');
        $categoryLabels = $filters->categories !== []
            ? DB::table('ticket_categories')->whereIn('slug', $filters->categories)->pluck('name', 'slug')
            : collect();

        return [
            'room_type' => $filters->roomType !== null ? [
                'value' => $filters->roomType,
                'label' => $filters->roomType === RoomType::Laboratory->value ? 'Laboratories' : 'Offices',
            ] : null,
            'building' => $building !== null ? [
                'id' => $building->uuid,
                'name' => $building->name,
                'archived' => $building->deleted_at !== null,
                'inactive' => ! $building->is_active,
            ] : null,
            'room' => $room !== null ? [
                'id' => $room->uuid,
                'label' => implode(' · ', array_values(array_filter([$room->floor?->building?->name, $room->floor?->name, $room->name]))),
                'archived' => $room->deleted_at !== null,
                'inactive' => ! $room->is_active,
            ] : null,
            'status' => array_map(fn (string $slug): array => ['slug' => $slug, 'label' => (string) ($statusLabels[$slug] ?? $slug)], $filters->statuses),
            'category' => array_map(fn (string $slug): array => ['slug' => $slug, 'label' => (string) ($categoryLabels[$slug] ?? $slug)], $filters->categories),
            'problem_area' => array_map(fn (string $area): array => ['value' => $area, 'label' => ProblemArea::tryFrom($area)?->label() ?? $area], $filters->problemAreas),
        ];
    }

    /**
     * When ticket records begin, cached for a day (keyed on the dashboard
     * version, so a first ticket on a fresh install is seen at once). Cached as
     * a string: the cache holds arrays and scalars only.
     */
    private function recordsStart(): ?CarbonImmutable
    {
        $iso = Cache::remember(
            'analytics:records-start:tickets:v'.$this->signal->version(),
            86400,
            fn (): ?string => $this->query->ticketsStartAt()?->toIso8601String(),
        );

        return is_string($iso) ? CarbonImmutable::parse($iso)->setTimezone(SchoolClock::TIMEZONE) : null;
    }
}
