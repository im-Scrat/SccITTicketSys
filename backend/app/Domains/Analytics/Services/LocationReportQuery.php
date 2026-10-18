<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportKpi;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Enums\OverallReportSection;
use App\Enums\ProblemArea;
use App\Enums\ReportRecordsTable;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Locations section of Overall Reports (Phase 2.10 R4, SDD DD-94).
 *
 * Problems (reported tickets, no duplicates, nothing cancelled) by the room
 * they were reported in, grouped by room, floor or building — a display choice,
 * not a filter — with the labs-versus-offices comparison and the room ranking
 * taken from the ticket overview itself, so they match the dashboard. Two
 * further views, each with its limitation stated:
 *
 *  - **problems per PC** — for rooms with at least three PC units, using
 *    today's PC count (rooms have no PC-count history);
 *  - **repairs by room** — completed repairs by the repaired equipment's
 *    room now (records carry no room).
 */
final class LocationReportQuery
{
    public const MIN_FOR_CHARTS = 5;

    private const PER_PC_MIN_UNITS = 3;

    public function __construct(
        private readonly TicketOverview $overview,
        private readonly TicketDirectoryQuery $directory,
        private readonly MaintenanceReportQuery $maintenance,
    ) {}

    public function section(OverallReportFilters $filters, User $actor): ReportDocumentSection
    {
        $report = $filters->report->withExtraScope($filters->equipmentParams());
        $payload = $this->overview->build($report, $actor);
        $locations = $payload['data']['locations'] ?? null;
        $problems = ['exclude_duplicates' => '1', 'exclude_cancelled' => '1'];
        $scope = $this->directory->reportScope($report->directoryParams(null, $problems), $actor)->toBase();

        $grouped = $this->grouped(clone $scope, $filters->locationGroup);
        $matrix = $this->matrix(clone $scope);
        $perPc = $this->perPc(clone $scope);
        $repairsByRoom = (clone $this->maintenance->repairs($filters))
            ->leftJoin('rooms as r', 'r.id', '=', DB::raw('COALESCE(p.room_id, a.current_room_id)'))
            ->groupBy('r.code', 'r.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(25)
            ->get(['r.code', 'r.name', DB::raw('count(*) as n')]);

        $located = (int) ($locations['located'] ?? 0);
        $total = (int) ($locations['problems_total'] ?? 0);
        $typeRows = array_map(static fn (array $t): array => [(string) $t['label'], (int) $t['count'], $t['rooms_with_problems'] ?? '—', $t['rooms_total'] ?? '—', $t['per_room'] !== null ? number_format((float) $t['per_room'], 1) : '—'], $locations['by_type'] ?? []);

        $groupNoun = ['room' => 'Room', 'floor' => 'Floor', 'building' => 'Building'][$filters->locationGroup];
        $groupTable = ReportTable::make('by_place', "Problems by {$filters->locationGroup}", [['place', $groupNoun], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['share', 'Share', ReportTable::TYPE_PERCENT]],
            array_map(static fn (array $r): array => [$r[0], $r[1], ($s = ReportMetrics::share($r[1], $located)) !== null ? number_format($s, 1).'%' : '—'], $grouped));

        $rankingLinks = array_map(static fn (array $r): ?array => $r['drilldown'], $locations['ranking'] ?? []);

        $kpis = [
            new ReportKpi('Problems', number_format($total), sprintf('%d in a recorded room', $located), null, $total),
            new ReportKpi('Rooms with problems', number_format((int) ($locations['rooms_with_problems'] ?? 0)), 'Reported in the period', null, (int) ($locations['rooms_with_problems'] ?? 0)),
            new ReportKpi('No room recorded', number_format($total - $located), 'Problems without a room', null, $total - $located),
            new ReportKpi('Repairs with a room', number_format((int) $repairsByRoom->whereNotNull('code')->sum('n')), "By the equipment's room now", null, (int) $repairsByRoom->whereNotNull('code')->sum('n')),
        ];

        $charts = [];
        $insufficient = null;
        if ($located >= self::MIN_FOR_CHARTS) {
            $top = array_slice($grouped, 0, 10);
            $charts[] = new ChartSpec(ChartSpec::BAR, "Problems by {$filters->locationGroup}", array_map(static fn (array $r): string => (string) $r[0], $top), [['name' => 'Problems', 'values' => array_map(static fn (array $r): int => (int) $r[1], $top), 'style' => 'current']], [ReportPalette::BAR], "Problems per {$filters->locationGroup}, most first (up to ten). The figures are in the table below.", 'by_place', 'Problems');
        } else {
            $insufficient = sprintf('Not enough located problems in this period to chart: %d in a recorded room (charts start at %d). The figures are below.', $located, self::MIN_FOR_CHARTS);
        }

        return new ReportDocumentSection(
            key: OverallReportSection::Locations->value,
            title: OverallReportSection::Locations->label(),
            intro: OverallReportSection::Locations->purpose(),
            kpis: $kpis,
            charts: $charts,
            tables: [
                $groupTable,
                ReportTable::make('room_types', 'Problems by room type', [['type', 'Room type'], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['rooms', 'Rooms with problems', ReportTable::TYPE_NUMBER], ['total', 'Rooms', ReportTable::TYPE_NUMBER], ['per_room', 'Per room', ReportTable::TYPE_NUMBER]], $typeRows),
                ReportTable::make('rooms', 'Rooms with the most problems', [['rank', 'Rank', ReportTable::TYPE_NUMBER], ['room', 'Room'], ['code', 'Code', ReportTable::TYPE_CODE], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['open', 'Still open', ReportTable::TYPE_NUMBER]],
                    array_map(static fn (array $r): array => [(int) $r['rank'], (string) $r['room']['label'].($r['room']['archived'] ? ' (archived)' : ''), (string) $r['room']['code'], (int) $r['count'], (int) $r['open']], $locations['ranking'] ?? []))->withLinks($rankingLinks),
                $matrix,
                ReportTable::make('per_pc', 'Problems per PC unit (rooms with '.self::PER_PC_MIN_UNITS.' or more)', [['room', 'Room'], ['code', 'Code', ReportTable::TYPE_CODE], ['pcs', 'PC units now', ReportTable::TYPE_NUMBER], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['per_pc', 'Per PC', ReportTable::TYPE_NUMBER]], $perPc),
                ReportTable::make('repairs_by_room', 'Completed repairs by room', [['room', 'Room'], ['code', 'Code', ReportTable::TYPE_CODE], ['count', 'Repairs', ReportTable::TYPE_NUMBER]], $repairsByRoom->map(static fn (object $r): array => [(string) ($r->name ?? 'No room'), (string) ($r->code ?? '—'), (int) $r->n])->all()),
            ],
            notes: [
                'Problems are counted in the room they were reported in; repairs in the equipment\'s room now.',
                'Problems per PC uses today\'s number of PC units in each room.',
            ],
            insufficient: $insufficient,
            records: [['table' => ReportRecordsTable::Rooms->value, 'title' => ReportRecordsTable::Rooms->label(), 'total' => (int) ($locations['rooms_with_problems'] ?? 0)]],
            notApplicable: $filters->maintenanceClasses !== [] || $filters->componentType !== null ? ['Repair class and component type apply to the repairs table only.'] : [],
        );
    }

    /**
     * @param  Builder  $scope
     * @return list<array{0: string, 1: int}>
     */
    private function grouped($scope, string $group): array
    {
        $scope->join('rooms as gr', 'gr.id', '=', 'tickets.room_id')
            ->leftJoin('floors as gf', 'gf.id', '=', 'gr.floor_id')
            ->leftJoin('buildings as gb', 'gb.id', '=', 'gf.building_id');

        $label = match ($group) {
            'building' => "COALESCE(gb.name, 'No building')",
            'floor' => "COALESCE(gb.name, 'No building') || ' · ' || COALESCE(NULLIF(gf.name, ''), 'Floor ' || gf.floor_number::text, 'No floor')",
            default => "COALESCE(gb.name || ' · ', '') || gr.name || ' (' || gr.code || ')'",
        };

        return $scope->selectRaw("{$label} as place, count(*) as n")
            ->groupByRaw('1')
            ->orderByDesc('n')->orderBy('place')
            ->get()
            ->map(static fn (object $r): array => [(string) $r->place, (int) $r->n])
            ->all();
    }

    /**
     * @param  Builder  $scope
     */
    private function matrix($scope): ReportTable
    {
        $areas = ProblemArea::cases();
        $select = ['mr.name', 'mr.code', DB::raw('count(*) as total')];
        foreach ($areas as $area) {
            $select[] = DB::raw("count(*) filter (where mc.problem_area = '{$area->value}') as \"{$area->value}\"");
        }

        $rows = $scope->join('rooms as mr', 'mr.id', '=', 'tickets.room_id')
            ->join('ticket_categories as mc', 'mc.id', '=', 'tickets.category_id')
            ->groupBy('mr.id', 'mr.name', 'mr.code')
            ->orderByDesc('total')
            ->limit(10)
            ->get($select);

        return ReportTable::make('area_by_room', 'Problem areas in the ten busiest rooms',
            [['room', 'Room'], ['code', 'Code', ReportTable::TYPE_CODE], ...array_map(static fn (ProblemArea $a): array => [$a->value, $a->label(), ReportTable::TYPE_NUMBER], $areas), ['total', 'Total', ReportTable::TYPE_NUMBER]],
            $rows->map(static function (object $r) use ($areas): array {
                $row = [(string) $r->name, (string) $r->code];
                foreach ($areas as $area) {
                    $row[] = (int) $r->{$area->value};
                }
                $row[] = (int) $r->total;

                return $row;
            })->all());
    }

    /**
     * @param  Builder  $scope
     * @return list<list<string|int>>
     */
    private function perPc($scope): array
    {
        $pcs = DB::table('pc_units')->whereNull('deleted_at')->whereNotNull('room_id')->groupBy('room_id')->havingRaw('count(*) >= ?', [self::PER_PC_MIN_UNITS])->select('room_id', DB::raw('count(*) as pcs'));

        return $scope->joinSub($pcs, 'pc', 'pc.room_id', '=', 'tickets.room_id')
            ->join('rooms as pr', 'pr.id', '=', 'tickets.room_id')
            ->groupBy('pr.name', 'pr.code', 'pc.pcs')
            ->orderByRaw('count(*)::numeric / pc.pcs desc')
            ->get(['pr.name', 'pr.code', 'pc.pcs', DB::raw('count(*) as n')])
            ->map(static fn (object $r): array => [(string) $r->name, (string) $r->code, (int) $r->pcs, (int) $r->n, number_format((int) $r->n / max(1, (int) $r->pcs), 2)])
            ->all();
    }
}
