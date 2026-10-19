<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Analytics\DTOs\ReportKpi;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Enums\MaintenanceClass;
use App\Enums\OverallReportSection;
use App\Enums\ProblemArea;
use App\Models\User;
use App\Support\SchoolClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Overall Reports (Phase 2.10 R4; SRS FR-RPT-001/002/010; SDD DD-94).
 *
 * One section at a time for the page, every visible section for a document —
 * both from the same section builders, so the page and its PDF cannot differ.
 * A section is built only if the viewer may see its module ({@see ReportsAccess}:
 * tickets, equipment, repairs, locations); one they may not see is left out,
 * never shown empty, and the Overview is assembled from visible sections only.
 *
 * Payloads are cached for 60 seconds as arrays, keyed on the dashboard version
 * (a realtime signal forces a fresh build), the absolute instants the filters
 * resolved to and the viewer's visible sections; access is asserted before the
 * cache is read.
 */
final class OverallReportService
{
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly ReportsAccess $access,
        private readonly TicketOverview $overview,
        private readonly TicketReportQuery $tickets,
        private readonly EquipmentReportQuery $equipment,
        private readonly MaintenanceReportQuery $maintenance,
        private readonly LocationReportQuery $locations,
        private readonly AdminDashboardSignal $signal,
    ) {}

    /**
     * The page payload for one section.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     *
     * @throws AuthorizationException
     */
    public function section(OverallReportSection $section, OverallReportFilters $filters, User $actor): array
    {
        $visible = $this->access->visibleSections($actor);

        if (! in_array($section, $visible, true)) {
            throw new AuthorizationException('This report section is not available to you.');
        }

        $key = sprintf(
            'analytics:overall:%s:v%d:%s',
            $section->value,
            $this->signal->version(),
            sha1(json_encode([$filters->cacheKeyParts(), array_map(static fn (OverallReportSection $s): string => $s->value, $visible)], JSON_THROW_ON_ERROR)),
        );

        /** @var array{data: array<string, mixed>, meta: array<string, mixed>} */
        return Cache::remember($key, self::TTL_SECONDS, fn (): array => [
            'data' => $this->build($section, $filters, $actor)->toArray(),
            'meta' => $this->meta($filters, $visible),
        ]);
    }

    /**
     * Every section the viewer may see, in page order — for documents.
     *
     * @return list<ReportDocumentSection>
     */
    public function documentSections(OverallReportFilters $filters, User $actor): array
    {
        return array_map(
            fn (OverallReportSection $s): ReportDocumentSection => $this->build($s, $filters, $actor),
            $this->access->visibleSections($actor),
        );
    }

    public function build(OverallReportSection $section, OverallReportFilters $filters, User $actor): ReportDocumentSection
    {
        return match ($section) {
            OverallReportSection::Overview => $this->overviewSection($filters, $actor),
            OverallReportSection::Tickets => $this->tickets->section($filters, $actor),
            OverallReportSection::Equipment => $this->equipment->section($filters),
            OverallReportSection::Repairs => $this->maintenance->section($filters),
            OverallReportSection::Locations => $this->locations->section($filters, $actor),
        };
    }

    /**
     * The period at a glance, from whichever sections the viewer may see.
     */
    private function overviewSection(OverallReportFilters $filters, User $actor): ReportDocumentSection
    {
        $visible = $this->access->visibleSections($actor);
        $report = $filters->report->withExtraScope($filters->equipmentParams());
        $kpis = [];
        $charts = [];
        $tables = [];

        if (in_array(OverallReportSection::Tickets, $visible, true)) {
            $payload = $this->overview->build($report, $actor);
            $data = $payload['data'];
            $reported = (int) $data['kpis']['reported']['value'];
            $kpis[] = new ReportKpi('Tickets reported', number_format($reported), $data['kpis']['reported']['change'] !== null ? sprintf('%+d vs %s', (int) $data['kpis']['reported']['change'], (string) ($payload['meta']['comparison']['range_label'] ?? 'before')) : null, $data['kpis']['reported']['drilldown'], $reported);

            $buckets = $data['series']['buckets'];
            if ($reported >= TicketReportQuery::MIN_FOR_CHARTS && count($buckets) > 1) {
                $series = [['name' => (string) $payload['meta']['period']['range_label'], 'values' => array_map(static fn (array $b): ?int => $b['count'], $buckets), 'style' => 'current']];
                $colors = [ReportPalette::CURRENT];
                if (is_array($data['series']['comparison'] ?? null)) {
                    $series[] = ['name' => (string) ($payload['meta']['comparison']['range_label'] ?? 'Comparison'), 'values' => array_map(static fn (int $i) => $data['series']['comparison']['buckets'][$i]['count'] ?? null, array_keys($buckets)), 'style' => 'compare'];
                    $colors[] = ReportPalette::COMPARE;
                }
                $charts[] = new ChartSpec(ChartSpec::LINE, 'Tickets reported over time', array_map(static fn (array $b): string => (string) $b['label'], $buckets), $series, $colors, 'Tickets reported per '.$data['series']['unit_label'].'. The figures are in the Tickets section.', null, keys: array_map(static fn (array $b): string => (string) $b['key'], $buckets));
            }

            $areas = $this->problemAreas($report);
            $tables[] = ReportTable::make('overview_areas', 'Problems by area', [['area', 'Problem area'], ['count', 'Problems', ReportTable::TYPE_NUMBER]], $areas);
            if (array_sum(array_column($areas, 1)) > 0) {
                $charts[] = new ChartSpec(ChartSpec::BAR, 'Problems by area', array_column($areas, 0), [['name' => 'Problems', 'values' => array_column($areas, 1), 'style' => 'current']], [ReportPalette::BAR], 'Problems (no duplicates, nothing cancelled) by problem area.', 'overview_areas', 'Problems');
            }

            if (is_array($data['locations'] ?? null)) {
                $top = array_slice($data['locations']['ranking'], 0, 5);
                $tables[] = ReportTable::make('overview_rooms', 'Top 5 locations', [['room', 'Room'], ['count', 'Problems', ReportTable::TYPE_NUMBER]], array_map(static fn (array $r): array => [(string) $r['room']['label'], (int) $r['count']], $top))
                    ->withLinks(array_map(static fn (array $r): ?array => $r['drilldown'], $top));
            }
        }

        if (in_array(OverallReportSection::Repairs, $visible, true)) {
            $figures = $this->maintenance->overviewFigures($filters);
            $kpis[] = new ReportKpi('Completed repairs', number_format($figures['completed']), sprintf('%d corrective', (int) ($figures['by_class']['corrective'] ?? 0)), null, $figures['completed']);
            $kpis[] = new ReportKpi('Hardware replacements', number_format($figures['replacements']), 'Parts replaced in the period', null, $figures['replacements']);
            $kpis[] = new ReportKpi('Flagged for preventive care', number_format($figures['candidates']), 'PC units, by fixed rules', null, $figures['candidates']);

            $classRows = array_map(static fn (MaintenanceClass $c): array => [$c->label(), (int) ($figures['by_class'][$c->value] ?? 0)], MaintenanceClass::cases());
            $tables[] = ReportTable::make('overview_repairs', 'Completed repairs by class', [['class', 'Class'], ['count', 'Completed', ReportTable::TYPE_NUMBER]], $classRows);
        }

        if (in_array(OverallReportSection::Equipment, $visible, true)) {
            $most = $this->equipment->mostProblems($filters);
            $tables[] = ReportTable::make('overview_equipment', 'Top 5 equipment', array_map(static fn (array $c): array => [$c['key'], $c['label'], $c['type']], $most->columns), array_slice($most->rows, 0, 5));
        }

        return new ReportDocumentSection(
            key: OverallReportSection::Overview->value,
            title: OverallReportSection::Overview->label(),
            intro: OverallReportSection::Overview->purpose(),
            kpis: $kpis,
            charts: $charts,
            tables: $tables,
            notes: ['Each figure is defined once in its own section; open the section for the full breakdown and records.'],
            insufficient: $charts === [] ? 'Not enough recorded activity in this period to chart; the figures are below.' : null,
        );
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function problemAreas(ReportFilters $report): array
    {
        $counts = DB::table('tickets as t')
            ->join('ticket_categories as c', 'c.id', '=', 't.category_id')
            ->whereNull('t.deleted_at')
            ->whereNull('t.duplicate_of_id')
            ->whereNotIn('t.current_status_id', DB::table('ticket_statuses')->select('id')->where('slug', 'cancelled'))
            ->where('t.created_at', '>=', $report->period->startUtc())
            ->where('t.created_at', '<', $report->period->endUtc());
        ReportScopes::location($counts, 't.room_id', $report);
        ReportScopes::ticketCategory($counts, $report);
        foreach (['pc_unit' => 'pc_units', 'asset' => 'assets'] as $param => $table) {
            if (isset($report->extraScope[$param])) {
                $counts->whereIn('t.'.$param.'_id', DB::table($table)->select('id')->where('uuid', $report->extraScope[$param]));
            }
        }
        $map = $counts->groupBy('c.problem_area')->selectRaw('c.problem_area as k, count(*) as n')->pluck('n', 'k');

        return array_map(static fn (ProblemArea $a): array => [$a->label(), (int) ($map[$a->value] ?? 0)], ProblemArea::cases());
    }

    /**
     * @param  list<OverallReportSection>  $visible
     * @return array<string, mixed>
     */
    private function meta(OverallReportFilters $filters, array $visible): array
    {
        $period = $filters->report->period;

        return [
            'report_type' => 'overall',
            'timezone' => SchoolClock::TIMEZONE,
            'generated_at' => now()->toIso8601ZuluString(),
            'as_of_label' => 'Data as of '.$period->asOf->subMinute()->format('j M Y, H:i').' ('.SchoolClock::TIMEZONE.')',
            'period' => $period->toArray(),
            'comparison' => $filters->report->comparison?->toArray(),
            'sections' => array_map(static fn (OverallReportSection $s): array => ['key' => $s->value, 'label' => $s->label()], $visible),
            'query' => $filters->toQuery(),
            'definitions' => ReportMetrics::ticketDefinitions(),
        ];
    }
}
