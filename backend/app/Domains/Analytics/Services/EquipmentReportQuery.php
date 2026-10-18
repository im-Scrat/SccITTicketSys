<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportKpi;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Domains\KnowledgeBase\Services\FailurePatternDetector;
use App\Domains\KnowledgeBase\Services\PcMaintenanceHistory;
use App\Domains\Maintenance\Services\ReplacementAttribution;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\MaintenanceClass;
use App\Enums\OverallReportSection;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Enums\ReportRecordsTable;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Equipment section of Overall Reports (Phase 2.10 R4, SDD DD-94).
 *
 * **Two clocks, said out loud.** The register — how many PC units and assets
 * there are, in which status and condition, under warranty or not — is *as of
 * today*: equipment has no status history to rewind. The period applies only
 * to "registered in the period" and to "most problems". The location filter
 * applies to both (the equipment's room now). Each table's title says which.
 *
 * With one piece of equipment chosen, the section becomes its **profile**: the
 * facts, its tickets, repairs and replacements in the period, any recurring
 * pattern the deterministic detector finds (never AI), and a merged timeline.
 */
final class EquipmentReportQuery
{
    private const WARRANTY_SOON_DAYS = 90;

    public function __construct(
        private readonly PcMaintenanceHistory $history,
        private readonly FailurePatternDetector $patterns,
    ) {}

    public function section(OverallReportFilters $filters): ReportDocumentSection
    {
        return $filters->hasEquipment() ? $this->profile($filters) : $this->register($filters);
    }

    private function register(OverallReportFilters $filters): ReportDocumentSection
    {
        $report = $filters->report;
        $today = SchoolClock::today();
        $soon = $today->addDays(self::WARRANTY_SOON_DAYS);

        $pcs = ReportScopes::location(DB::table('pc_units as p')->whereNull('p.deleted_at'), 'p.room_id', $report);
        $assets = ReportScopes::location(DB::table('assets as a')->whereNull('a.deleted_at'), 'a.current_room_id', $report);

        $pcTotal = (clone $pcs)->count();
        $assetTotal = (clone $assets)->count();

        $pcStatus = (clone $pcs)->groupBy('p.status')->selectRaw('p.status as k, count(*) as n')->pluck('n', 'k');
        $pcCondition = (clone $pcs)->groupBy('p.current_condition')->selectRaw('p.current_condition as k, count(*) as n')->pluck('n', 'k');
        $assetStatus = (clone $assets)->groupBy('a.status')->selectRaw('a.status as k, count(*) as n')->pluck('n', 'k');
        $assetCategory = (clone $assets)
            ->leftJoin('hardware_models as hm', 'hm.id', '=', 'a.hardware_model_id')
            ->leftJoin('hardware_components as hc', 'hc.id', '=', 'hm.hardware_component_id')
            ->groupBy('hc.component_type')->selectRaw('hc.component_type as k, count(*) as n')->pluck('n', 'k');

        $warranty = fn ($q, string $col): array => [
            'expired' => (clone $q)->whereNotNull($col)->where($col, '<', $today->format('Y-m-d'))->count(),
            'soon' => (clone $q)->whereNotNull($col)->where($col, '>=', $today->format('Y-m-d'))->where($col, '<=', $soon->format('Y-m-d'))->count(),
            'unknown' => (clone $q)->whereNull($col)->count(),
        ];
        $pcWarranty = $warranty($pcs, 'p.warranty_expiration');
        $assetWarranty = $warranty($assets, 'a.warranty_expiration');

        $start = $report->period->startUtc();
        $end = $report->period->endUtc();
        $pcAdded = (clone $pcs)->where('p.created_at', '>=', $start)->where('p.created_at', '<', $end)->count();
        $assetAdded = (clone $assets)->where('a.created_at', '>=', $start)->where('a.created_at', '<', $end)->count();

        $problems = $this->mostProblems($filters);

        $kpis = [
            new ReportKpi('PC units', number_format($pcTotal), 'In the register today', null, $pcTotal),
            new ReportKpi('Other equipment', number_format($assetTotal), 'In the register today', null, $assetTotal),
            new ReportKpi('Registered in period', number_format($pcAdded + $assetAdded), sprintf('%d PC units, %d other', $pcAdded, $assetAdded), null, $pcAdded + $assetAdded),
            new ReportKpi('Warranty ending soon', number_format($pcWarranty['soon'] + $assetWarranty['soon']), sprintf('Within %d days', self::WARRANTY_SOON_DAYS), null, $pcWarranty['soon'] + $assetWarranty['soon']),
        ];

        $statusRows = [];
        foreach (PcStatus::cases() as $s) {
            $statusRows[] = ['PC unit', $s->label(), (int) ($pcStatus[$s->value] ?? 0)];
        }
        foreach (AssetStatus::cases() as $s) {
            if (($assetStatus[$s->value] ?? 0) > 0) {
                $statusRows[] = ['Other equipment', $s->label(), (int) $assetStatus[$s->value]];
            }
        }

        $conditionRows = array_map(fn (PcCondition $c): array => [$this->conditionLabel($c), (int) ($pcCondition[$c->value] ?? 0)], PcCondition::cases());
        $categoryRows = [];
        foreach ($assetCategory as $type => $n) {
            $categoryRows[] = [$type !== '' ? (ComponentType::tryFrom((string) $type)?->label() ?? (string) $type) : 'No category', (int) $n];
        }
        usort($categoryRows, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        $tables = [
            ReportTable::make('equipment_status', 'Status today', [['kind', 'Kind'], ['status', 'Status'], ['count', 'Count', ReportTable::TYPE_NUMBER]], $statusRows),
            ReportTable::make('pc_condition', 'PC unit condition today', [['condition', 'Condition'], ['count', 'PC units', ReportTable::TYPE_NUMBER]], $conditionRows),
            ReportTable::make('asset_categories', 'Other equipment by category today', [['category', 'Category'], ['count', 'Items', ReportTable::TYPE_NUMBER]], $categoryRows),
            ReportTable::make('warranty', 'Warranty today', [['kind', 'Kind'], ['expired', 'Ended', ReportTable::TYPE_NUMBER], ['soon', 'Ends within '.self::WARRANTY_SOON_DAYS.' days', ReportTable::TYPE_NUMBER], ['unknown', 'Not recorded', ReportTable::TYPE_NUMBER]], [
                ['PC units', $pcWarranty['expired'], $pcWarranty['soon'], $pcWarranty['unknown']],
                ['Other equipment', $assetWarranty['expired'], $assetWarranty['soon'], $assetWarranty['unknown']],
            ]),
            $problems,
        ];

        $charts = [];
        $insufficient = null;
        if ($problems->rows !== []) {
            $top = array_slice($problems->rows, 0, 10);
            $charts[] = new ChartSpec(ChartSpec::BAR, 'Equipment with the most problems', array_map(static fn (array $r): string => (string) $r[0], $top), [['name' => 'Problems', 'values' => array_map(static fn (array $r): int => (int) $r[2], $top), 'style' => 'current']], [ReportPalette::BAR], 'Problems reported on each piece of equipment in the period, most first. The figures are in the table below.', 'most_problems', 'Problems');
        }
        if ($pcTotal + $assetTotal === 0) {
            $insufficient = 'No equipment is registered in this location yet.';
        }

        return new ReportDocumentSection(
            key: OverallReportSection::Equipment->value,
            title: OverallReportSection::Equipment->label(),
            intro: OverallReportSection::Equipment->purpose(),
            kpis: $kpis,
            charts: $charts,
            tables: $tables,
            notes: ['Status, condition and warranty are as of today; the period applies to "Registered in period" and to problems.', 'Location means the room the equipment is in now.'],
            insufficient: $insufficient,
            records: [
                ['table' => ReportRecordsTable::PcUnits->value, 'title' => ReportRecordsTable::PcUnits->label(), 'total' => $pcTotal],
                ['table' => ReportRecordsTable::Assets->value, 'title' => ReportRecordsTable::Assets->label(), 'total' => $assetTotal],
            ],
            notApplicable: $this->notApplicable($filters),
        );
    }

    /** Problems per piece of equipment in the period (no duplicates, nothing cancelled). */
    public function mostProblems(OverallReportFilters $filters): ReportTable
    {
        $report = $filters->report;
        $base = DB::table('tickets as t')
            ->whereNull('t.deleted_at')
            ->whereNull('t.duplicate_of_id')
            ->where('t.created_at', '>=', $report->period->startUtc())
            ->where('t.created_at', '<', $report->period->endUtc())
            ->whereNotIn('t.current_status_id', DB::table('ticket_statuses')->select('id')->where('slug', 'cancelled'));
        $base = ReportScopes::location($base, 't.room_id', $report);
        $base = ReportScopes::ticketCategory($base, $report);

        $pcs = (clone $base)->join('pc_units as p', 'p.id', '=', 't.pc_unit_id')
            ->groupBy('p.id', 'p.uuid', 'p.unit_code', 'p.pc_name')
            ->get(['p.uuid', 'p.unit_code as code', 'p.pc_name as name', DB::raw("'PC unit' as kind"), DB::raw('count(*) as n')]);
        $assets = (clone $base)->join('assets as a', 'a.id', '=', 't.asset_id')
            ->groupBy('a.id', 'a.uuid', 'a.asset_tag', 'a.name')
            ->get(['a.uuid', 'a.asset_tag as code', 'a.name as name', DB::raw("'Other equipment' as kind"), DB::raw('count(*) as n')]);

        $rows = $pcs->concat($assets)->sortBy([['n', 'desc'], ['code', 'asc']])->values()->take(50);

        return ReportTable::make('most_problems', 'Equipment with the most problems (in the period)', [['code', 'Code', ReportTable::TYPE_CODE], ['kind', 'Kind'], ['count', 'Problems', ReportTable::TYPE_NUMBER], ['name', 'Name']],
            $rows->map(static fn (object $r): array => [(string) $r->code, (string) $r->kind, (int) $r->n, (string) ($r->name ?? '')])->all());
    }

    private function profile(OverallReportFilters $filters): ReportDocumentSection
    {
        $report = $filters->report;
        $start = $report->period->startUtc();
        $end = $report->period->endUtc();
        $isPc = $filters->equipmentType === 'pc';

        /** @var PcUnit|Asset $item */
        $item = $isPc
            ? PcUnit::withTrashed()->with(['room' => fn ($q) => $q->withTrashed()])->where('uuid', $filters->equipmentUuid)->firstOrFail()
            : Asset::withTrashed()->with(['currentRoom' => fn ($q) => $q->withTrashed()])->where('uuid', $filters->equipmentUuid)->firstOrFail();
        $column = $isPc ? 'pc_unit_id' : 'asset_id';

        $tickets = DB::table('tickets as t')->whereNull('t.deleted_at')->where("t.{$column}", $item->id)
            ->where('t.created_at', '>=', $start)->where('t.created_at', '<', $end)
            ->leftJoin('ticket_categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('ticket_statuses as s', 's.id', '=', 't.current_status_id')
            ->orderBy('t.created_at')
            ->get(['t.created_at', 't.ticket_number', 'c.name as category', 's.name as status']);

        $repairs = DB::table('maintenance_records as m')->whereNull('m.deleted_at')->where("m.{$column}", $item->id)
            ->where('m.status', 'completed')->where('m.completed_at', '>=', $start)->where('m.completed_at', '<', $end)
            ->join('maintenance_types as mt', 'mt.id', '=', 'm.maintenance_type_id')
            ->orderBy('m.completed_at')
            ->get(['m.completed_at', 'mt.name as type', DB::raw(MaintenanceClass::sqlCase().' as class')]);

        $replacements = ReplacementAttribution::joins(DB::table('hardware_replacements as hr')
            ->join('maintenance_records as m', 'm.id', '=', 'hr.maintenance_record_id')
            ->whereNull('m.deleted_at')->where('m.status', '<>', 'cancelled')
            ->where($isPc ? 'hr.pc_unit_id' : 'm.asset_id', $item->id)
            ->whereRaw('COALESCE(hr.replaced_at, hr.created_at) >= ?', [$start])
            ->whereRaw('COALESCE(hr.replaced_at, hr.created_at) < ?', [$end]))
            ->orderByRaw('COALESCE(hr.replaced_at, hr.created_at)')
            ->get([DB::raw('COALESCE(hr.replaced_at, hr.created_at) as at'), DB::raw(ReplacementAttribution::typeSql().' as type'), DB::raw(ReplacementAttribution::basisSql().' as basis')]);

        $timeline = [];
        foreach ($tickets as $t) {
            $timeline[] = [(string) $t->created_at, 'Ticket', (string) $t->ticket_number, trim(($t->category ?? '').' · '.($t->status ?? ''), ' ·')];
        }
        foreach ($repairs as $r) {
            $timeline[] = [(string) $r->completed_at, 'Repair', ucfirst((string) $r->class), (string) $r->type];
        }
        foreach ($replacements as $x) {
            $timeline[] = [(string) $x->at, 'Replacement', $x->type !== null ? (ComponentType::tryFrom((string) $x->type)?->label() ?? (string) $x->type) : 'Part not recorded', ReplacementAttribution::LABELS[(string) $x->basis] ?? ''];
        }
        usort($timeline, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $timeline = array_map(static fn (array $r): array => [CarbonImmutable::parse($r[0])->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d H:i'), $r[1], $r[2], $r[3]], $timeline);

        $patternRows = [];
        if ($item instanceof PcUnit) {
            foreach ($this->patterns->detect($this->history->for($item), SchoolClock::now()) as $pattern) {
                $patternRows[] = [$pattern->name, $pattern->occurrenceCount, $pattern->firstAt->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d'), $pattern->lastAt->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d')];
            }
        }

        $code = $item instanceof PcUnit ? $item->unit_code : $item->asset_tag;
        $name = $item instanceof PcUnit ? $item->pc_name : (string) $item->name;
        $room = $item instanceof PcUnit ? $item->room : $item->currentRoom;

        $facts = ReportTable::make('profile', 'Equipment', [['fact', 'Fact'], ['value', 'Value']], array_values(array_filter([
            ['Code', (string) $code],
            ['Name', $name],
            ['Kind', $item instanceof PcUnit ? 'PC unit' : 'Other equipment'],
            ['Room now', $room !== null ? $room->code.' · '.$room->name.($room->deleted_at !== null ? ' (archived)' : '') : 'No room'],
            ['Status', $item->status->label()],
            $item instanceof PcUnit ? ['Condition', $this->conditionLabel($item->current_condition)] : null,
            ['Purchased', $item->purchase_date?->format('Y-m-d') ?? 'Not recorded'],
            ['Warranty until', $item->warranty_expiration?->format('Y-m-d') ?? 'Not recorded'],
        ])));

        return new ReportDocumentSection(
            key: OverallReportSection::Equipment->value,
            title: OverallReportSection::Equipment->label().': '.$code,
            intro: 'The history of one piece of equipment in the period.',
            kpis: [
                new ReportKpi('Tickets', (string) count($tickets), 'Reported on it in the period', null, count($tickets)),
                new ReportKpi('Completed repairs', (string) count($repairs), 'In the period', null, count($repairs)),
                new ReportKpi('Replacements', (string) count($replacements), 'Parts replaced in the period', null, count($replacements)),
                new ReportKpi('Recurring patterns', (string) count($patternRows), $item instanceof PcUnit ? 'Found by counting repairs (rule-based)' : 'Detected for PC units only', null, count($patternRows)),
            ],
            tables: [
                $facts,
                ReportTable::make('patterns', 'Recurring patterns (rule-based, all history)', [['pattern', 'Pattern'], ['count', 'Occurrences', ReportTable::TYPE_NUMBER], ['first', 'First'], ['last', 'Latest']], $patternRows),
                ReportTable::make('timeline', 'Timeline in the period', [['at', 'When ('.SchoolClock::TIMEZONE.')'], ['kind', 'What'], ['ref', 'Reference', ReportTable::TYPE_CODE], ['detail', 'Detail']], $timeline),
            ],
            notes: ['Patterns are counted from completed corrective repairs (the same rules as predictive maintenance); they are not AI.'],
            insufficient: $timeline === [] ? 'Nothing was recorded for this equipment in the period.' : null,
            notApplicable: $this->notApplicable($filters),
        );
    }

    private function conditionLabel(PcCondition $condition): string
    {
        return ucfirst(str_replace('_', ' ', $condition->value));
    }

    /**
     * @return list<string>
     */
    private function notApplicable(OverallReportFilters $filters): array
    {
        $notes = [];
        if ($filters->report->hasStatusFilter()) {
            $notes[] = 'The ticket status filter does not apply to the equipment register.';
        }
        if ($filters->maintenanceClasses !== [] || $filters->componentType !== null) {
            $notes[] = 'Repair class and component type apply to Repairs only.';
        }

        return $notes;
    }
}
