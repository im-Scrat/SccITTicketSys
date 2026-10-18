<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportKpi;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Enums\OverallReportSection;
use App\Enums\ProblemArea;
use App\Enums\ReportRecordsTable;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Tickets section of Overall Reports (Phase 2.10 R4, SDD DD-94).
 *
 * Built **on the ticket overview**, not beside it: reported, still open,
 * resolved in period, the series and the status mix come from
 * `TicketOverview::build()` itself, so this page and the dashboard cannot show
 * different numbers for the same filters (a test compares them). What it adds
 * is lifecycle and timing, each with one definition:
 *
 *  - closed and cancelled in the period, and sent back after resolution —
 *    events from `ticket_status_history`, any report date (like "resolved in
 *    period"), the location/category/equipment scope only;
 *  - open at the period's end — each ticket's last status before the end;
 *  - overdue now — the shared FR-TKT-017 predicate (DD-81);
 *  - median first response and median time to resolution — the latter over
 *    staff resolutions only (a reporter closing their own ticket is not a
 *    repair time), first entry into Resolved.
 */
final class TicketReportQuery
{
    /** Below this many reported tickets, charts would show noise, not a pattern. */
    public const MIN_FOR_CHARTS = 5;

    public function __construct(
        private readonly TicketOverview $overview,
        private readonly TicketDirectoryQuery $directory,
    ) {}

    public function section(OverallReportFilters $filters, User $actor): ReportDocumentSection
    {
        $report = $filters->report->withExtraScope($filters->equipmentParams());
        $payload = $this->overview->build($report, $actor);
        $data = $payload['data'];
        $meta = $payload['meta'];
        $period = $report->period;

        $scopeIds = $this->directory->reportScope($report->scopeParams(), $actor)->toBase()->select('tickets.id');
        $events = $this->events($scopeIds, $report);
        $reported = (int) $data['kpis']['reported']['value'];

        $cohort = $this->directory->reportScope($report->directoryParams(), $actor)->toBase();
        $firstResponse = $this->medianMinutes((clone $cohort)->whereNotNull('tickets.first_response_at'), 'tickets.first_response_at', 'tickets.created_at');
        $resolution = $this->medianResolutionHours($scopeIds, $report);
        $openAtEnd = $this->openAtEnd($scopeIds, $report);
        $overdue = SlaCalculator::constrainBreached(SlaCalculator::constrainLive($this->directory->reportScope($report->scopeParams(), $actor)))->count();

        $kpis = [
            new ReportKpi('Reported', number_format($reported), $this->included($data), $data['kpis']['reported']['drilldown'], $reported),
            new ReportKpi('Resolved in period', number_format((int) $data['kpis']['resolved_in_period']['value']), 'Moved to Resolved in the period, whenever reported', null, (int) $data['kpis']['resolved_in_period']['value']),
            new ReportKpi('Still open', number_format((int) $data['kpis']['open_now']['value']), 'Of those reported in the period', $data['kpis']['open_now']['drilldown'], (int) $data['kpis']['open_now']['value']),
            new ReportKpi('Overdue now', number_format($overdue), 'A response or fix-by deadline passed and not met', TicketDrilldown::for($report, ['breached' => '1', 'reported_from' => null, 'reported_to' => null]), $overdue),
        ];

        $lifecycle = ReportTable::make('lifecycle', 'How tickets moved', [['figure', 'Figure'], ['value', 'Value', ReportTable::TYPE_NUMBER], ['basis', 'How it is counted']], [
            ['Reported', $reported, 'Filed in the period'],
            ['Duplicates among them', (int) $data['kpis']['reported']['duplicates'], 'Marked a duplicate of another report'],
            ['Resolved in period', (int) $data['kpis']['resolved_in_period']['value'], 'Moved to Resolved in the period, any report date'],
            ['Closed in period', $events['closed'], 'Moved to Closed in the period, any report date'],
            ['Cancelled in period', $events['cancelled'], 'Moved to Cancelled in the period, any report date'],
            ['Sent back after resolution', $events['sent_back'], 'Reopened or returned to work after Resolved or Closed'],
            ['Open at the end of the period', $openAtEnd['open'], $openAtEnd['without_history'] > 0 ? sprintf('From status history; %d without history not counted', $openAtEnd['without_history']) : 'From status history'],
            ['Still open now', (int) $data['kpis']['open_now']['value'], 'Of those reported in the period'],
            ['Overdue now', $overdue, 'Response or fix-by deadline passed (all open tickets in scope)'],
            ['Median first response', $firstResponse === null ? '—' : $this->duration($firstResponse * 60), 'Reported in the period and answered'],
            ['Median time to resolution', $resolution === null ? '—' : $this->duration($resolution * 3600), 'Resolved by staff in the period; first resolution'],
        ]);

        $categories = $this->categories($report, $actor);
        $priorities = $this->priorities($report, $actor);
        $areas = $this->areas($categories);

        $charts = [];
        $insufficient = null;

        if ($reported >= self::MIN_FOR_CHARTS) {
            $buckets = $data['series']['buckets'];
            if (count($buckets) > 1) {
                $series = [['name' => (string) $meta['period']['range_label'], 'values' => array_map(static fn (array $b): ?int => $b['count'], $buckets), 'style' => 'current']];
                $colors = [ReportPalette::CURRENT];
                if (is_array($data['series']['comparison'] ?? null)) {
                    $series[] = [
                        'name' => (string) ($meta['comparison']['range_label'] ?? 'Comparison'),
                        'values' => array_map(static fn (int $i) => $data['series']['comparison']['buckets'][$i]['count'] ?? null, array_keys($buckets)),
                        'style' => 'compare',
                    ];
                    $colors[] = ReportPalette::COMPARE;
                }
                $charts[] = new ChartSpec(ChartSpec::LINE, 'Tickets reported over time', array_map(static fn (array $b): string => (string) $b['label'], $buckets), $series, $colors, 'Tickets reported per '.$data['series']['unit_label'].'. The figures are in the table below.', 'over_time', keys: array_map(static fn (array $b): string => (string) $b['key'], $buckets));
            }

            $present = array_values(array_filter($data['status_distribution']['rows'], static fn (array $r): bool => (int) $r['count'] > 0));
            $charts[] = new ChartSpec(ChartSpec::DONUT, 'Current status', array_map(static fn (array $r): string => (string) $r['label'], $present), [['name' => 'Tickets', 'values' => array_map(static fn (array $r): int => (int) $r['count'], $present), 'style' => 'slices']], array_map(static fn (array $r): string => ReportPalette::status((string) $r['slug']), $present), 'Reported tickets by their status now. The figures are in the table below.', 'statuses', keys: array_map(static fn (array $r): string => (string) $r['slug'], $present));

            $charts[] = new ChartSpec(ChartSpec::BAR, 'Problems by area', array_map(static fn (array $r): string => (string) $r[0], $areas->rows), [['name' => 'Problems', 'values' => array_map(static fn (array $r): int => (int) $r[1], $areas->rows), 'style' => 'current']], [ReportPalette::BAR], 'Problems (no duplicates, nothing cancelled) by problem area. The figures are in the table below.', 'areas', 'Problems');
        } else {
            $insufficient = sprintf('Not enough tickets in this period to chart: %d reported (charts start at %d). The figures and records are below.', $reported, self::MIN_FOR_CHARTS);
        }

        $statusTable = ReportTable::make('statuses', 'Current status', [['status', 'Status'], ['count', 'Tickets', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]],
            array_map(static fn (array $r): array => [(string) $r['label'], (int) $r['count'], $r['pct'] !== null ? number_format((float) $r['pct'], 1).'%' : '—'], $data['status_distribution']['rows']));
        $statusTable = $statusTable->withLinks(array_map(static fn (array $r): ?array => (int) $r['count'] > 0 ? $r['drilldown'] : null, $data['status_distribution']['rows']));

        $overTime = ReportTable::make('over_time', 'Tickets reported per '.$data['series']['unit_label'], [['bucket', ucfirst((string) $data['series']['unit_label'])], ['count', 'Reported', ReportTable::TYPE_NUMBER]],
            array_map(static fn (array $b): array => [(string) $b['label'], $b['count'] ?? 'Before records'], $data['series']['buckets']));

        return new ReportDocumentSection(
            key: OverallReportSection::Tickets->value,
            title: OverallReportSection::Tickets->label(),
            intro: OverallReportSection::Tickets->purpose(),
            kpis: $kpis,
            charts: $charts,
            tables: [$lifecycle, $statusTable, $areas, $categories, $priorities, $overTime],
            notes: [
                'Problems are reported tickets that are neither duplicates nor cancelled.',
                'Times are school time ('.$meta['timezone'].'). Retired categories keep their own name and area.',
            ],
            insufficient: $insufficient,
            records: [['table' => ReportRecordsTable::Tickets->value, 'title' => ReportRecordsTable::Tickets->label(), 'total' => $reported]],
            notApplicable: $filters->maintenanceClasses !== [] || $filters->componentType !== null ? ['Repair class and component type apply to Repairs only.'] : [],
        );
    }

    /**
     * @param  Builder  $scopeIds
     * @return array{closed: int, cancelled: int, sent_back: int}
     */
    private function events($scopeIds, ReportFilters $report): array
    {
        $row = DB::table('ticket_status_history as h')
            ->join('ticket_statuses as t', 't.id', '=', 'h.to_status_id')
            ->leftJoin('ticket_statuses as f', 'f.id', '=', 'h.from_status_id')
            ->whereIn('h.ticket_id', $scopeIds)
            ->where('h.created_at', '>=', $report->period->startUtc())
            ->where('h.created_at', '<', $report->period->endUtc())
            ->selectRaw("count(distinct case when t.slug = 'closed' then h.ticket_id end) as closed")
            ->selectRaw("count(distinct case when t.slug = 'cancelled' then h.ticket_id end) as cancelled")
            ->selectRaw("count(case when f.slug in ('resolved','closed') and t.slug in ('open','in-progress','assigned') then 1 end) as sent_back")
            ->first();

        return ['closed' => (int) ($row->closed ?? 0), 'cancelled' => (int) ($row->cancelled ?? 0), 'sent_back' => (int) ($row->sent_back ?? 0)];
    }

    /**
     * @param  Builder  $scopeIds
     * @return array{open: int, without_history: int}
     */
    private function openAtEnd($scopeIds, ReportFilters $report): array
    {
        $end = $report->period->endUtc();
        $latest = DB::table('ticket_status_history as h')
            ->select('h.ticket_id', 'h.to_status_id')
            ->selectRaw('row_number() over (partition by h.ticket_id order by h.created_at desc, h.id desc) as n')
            ->where('h.created_at', '<', $end);

        $base = DB::table('tickets')
            ->whereIn('tickets.id', $scopeIds)
            ->where('tickets.created_at', '<', $end);

        $open = (clone $base)
            ->joinSub($latest, 'l', fn ($j) => $j->on('l.ticket_id', '=', 'tickets.id')->where('l.n', '=', 1))
            ->join('ticket_statuses as s', 's.id', '=', 'l.to_status_id')
            ->where('s.is_open', true)
            ->count();

        $without = (clone $base)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ticket_status_history as x')->whereColumn('x.ticket_id', 'tickets.id')->where('x.created_at', '<', $end))
            ->count();

        return ['open' => $open, 'without_history' => $without];
    }

    /**
     * @param  Builder  $query
     */
    private function medianMinutes($query, string $later, string $earlier): ?float
    {
        $value = $query->selectRaw("percentile_cont(0.5) within group (order by extract(epoch from ({$later} - {$earlier})) / 60) as m")->value('m');

        return $value === null ? null : round((float) $value, 1);
    }

    /**
     * @param  Builder  $scopeIds
     */
    private function medianResolutionHours($scopeIds, ReportFilters $report): ?float
    {
        $first = DB::table('ticket_status_history as h')
            ->join('ticket_statuses as s', 's.id', '=', 'h.to_status_id')
            ->join('tickets as t', 't.id', '=', 'h.ticket_id')
            ->where('s.slug', 'resolved')
            ->whereIn('h.ticket_id', $scopeIds)
            ->select('h.ticket_id', 'h.created_at', 'h.changed_by', 't.reporter_id', 't.created_at as reported_at')
            ->selectRaw('row_number() over (partition by h.ticket_id order by h.created_at, h.id) as n');

        $value = DB::query()->fromSub($first, 'r')
            ->where('r.n', 1)
            ->whereNotNull('r.changed_by')
            ->whereColumn('r.changed_by', '<>', 'r.reporter_id')
            ->where('r.created_at', '>=', $report->period->startUtc())
            ->where('r.created_at', '<', $report->period->endUtc())
            ->selectRaw('percentile_cont(0.5) within group (order by extract(epoch from (r.created_at - r.reported_at)) / 3600) as m')
            ->value('m');

        return $value === null ? null : round((float) $value, 1);
    }

    private function categories(ReportFilters $report, User $actor): ReportTable
    {
        $problems = ['exclude_duplicates' => '1', 'exclude_cancelled' => '1'];
        $rows = $this->directory->reportScope($report->directoryParams(null, $problems), $actor)->toBase()
            ->join('ticket_categories as c', 'c.id', '=', 'tickets.category_id')
            ->groupBy('c.slug', 'c.name', 'c.problem_area', 'c.is_active')
            ->orderByDesc(DB::raw('count(*)'))->orderBy('c.name')
            ->get(['c.slug', 'c.name', 'c.problem_area', 'c.is_active', DB::raw('count(*) as n')]);
        $total = (int) $rows->sum('n');

        $table = ReportTable::make('categories', 'Problems by category', [['category', 'Category'], ['area', 'Problem area'], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]],
            $rows->map(fn (object $r): array => [
                (string) $r->name.($r->is_active ? '' : ' (retired)'),
                ProblemArea::tryFrom((string) $r->problem_area)?->label() ?? (string) $r->problem_area,
                (int) $r->n,
                ($s = ReportMetrics::share((int) $r->n, $total)) !== null ? number_format($s, 1).'%' : '—',
            ])->values()->all());

        return $table->withLinks($rows->map(fn (object $r): ?array => TicketDrilldown::for($report, [...$problems, 'category' => (string) $r->slug]))->values()->all());
    }

    private function areas(ReportTable $categories): ReportTable
    {
        $totals = [];
        foreach (ProblemArea::cases() as $area) {
            $totals[$area->label()] = 0;
        }
        foreach ($categories->rows as $row) {
            $totals[(string) $row[1]] = ($totals[(string) $row[1]] ?? 0) + (int) $row[2];
        }
        arsort($totals);
        $sum = array_sum($totals);

        return ReportTable::make('areas', 'Problems by area', [['area', 'Problem area'], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]],
            array_map(static fn (string $label, int $n): array => [$label, $n, ($s = ReportMetrics::share($n, $sum)) !== null ? number_format($s, 1).'%' : '—'], array_keys($totals), array_values($totals)));
    }

    private function priorities(ReportFilters $report, User $actor): ReportTable
    {
        $rows = $this->directory->reportScope($report->directoryParams(), $actor)->toBase()
            ->leftJoin('ticket_priorities as p', 'p.id', '=', 'tickets.priority_id')
            ->groupBy('p.slug', 'p.name', 'p.level')
            ->orderBy('p.level')
            ->get(['p.slug', 'p.name', DB::raw('count(*) as n')]);
        $total = (int) $rows->sum('n');

        return ReportTable::make('priorities', 'Reported by priority', [['priority', 'Priority'], ['count', 'Tickets', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]],
            $rows->map(fn (object $r): array => [(string) ($r->name ?? 'Not set'), (int) $r->n, ($s = ReportMetrics::share((int) $r->n, $total)) !== null ? number_format($s, 1).'%' : '—'])->values()->all());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function included(array $data): ?string
    {
        $d = (int) $data['kpis']['reported']['duplicates'];
        $c = (int) $data['kpis']['reported']['cancelled'];
        $parts = array_values(array_filter([$d > 0 ? "{$d} duplicate".($d === 1 ? '' : 's') : null, $c > 0 ? "{$c} cancelled" : null]));

        return $parts !== [] ? 'incl. '.implode(', ', $parts) : null;
    }

    private function duration(float $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        return match (true) {
            $minutes < 60 => "{$minutes} min",
            $minutes < 48 * 60 => sprintf('%.1f h', $minutes / 60),
            default => sprintf('%.1f days', $minutes / 1440),
        };
    }
}
