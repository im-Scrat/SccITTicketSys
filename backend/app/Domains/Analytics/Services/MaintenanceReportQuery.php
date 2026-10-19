<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportBucket;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportKpi;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Domains\Maintenance\Services\ReplacementAttribution;
use App\Enums\ComponentType;
use App\Enums\MaintenanceClass;
use App\Enums\OverallReportSection;
use App\Enums\ReportRecordsTable;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Repairs & maintenance section of Overall Reports (Phase 2.10 R4, SDD
 * DD-94), over two populations, each defined once here:
 *
 *  - **completed repairs** — maintenance records completed in the period,
 *    not archived, classed corrective / preventive / upgrade
 *    ({@see MaintenanceClass});
 *  - **replacements** — parts replaced in the period on records that are
 *    neither archived nor cancelled; the failed part is read by
 *    {@see ReplacementAttribution} (the old part first), with its basis.
 *
 * Location is the repaired equipment's room **now** (records carry no room of
 * their own; the section says so). Downtime, labour and cost are summed with
 * their coverage ("recorded on 3 of 7"), and cost carries no currency symbol:
 * none is stored.
 */
final class MaintenanceReportQuery
{
    public const MIN_FOR_CHARTS = 3;

    public function __construct(private readonly PreventiveMaintenanceRules $preventive) {}

    /** Completed repairs in the period, in scope. */
    public function repairs(OverallReportFilters $filters): Builder
    {
        $report = $filters->report;
        $query = DB::table('maintenance_records as m')
            ->join('maintenance_types as mt', 'mt.id', '=', 'm.maintenance_type_id')
            ->leftJoin('pc_units as p', 'p.id', '=', 'm.pc_unit_id')
            ->leftJoin('assets as a', 'a.id', '=', 'm.asset_id')
            ->whereNull('m.deleted_at')
            ->where('m.status', 'completed')
            ->where('m.completed_at', '>=', $report->period->startUtc())
            ->where('m.completed_at', '<', $report->period->endUtc());

        ReportScopes::location($query, 'COALESCE(p.room_id, a.current_room_id)', $report);
        $this->equipment($query, $filters, 'm');

        if ($filters->maintenanceClasses !== []) {
            $query->whereIn(DB::raw(MaintenanceClass::sqlCase()), $filters->maintenanceClasses);
        }

        return $query;
    }

    /** Replacements in the period, in scope, with the attribution joins. */
    public function replacements(OverallReportFilters $filters): Builder
    {
        $report = $filters->report;
        $query = ReplacementAttribution::joins(DB::table('hardware_replacements as hr')
            ->join('maintenance_records as m', 'm.id', '=', 'hr.maintenance_record_id')
            ->leftJoin('pc_units as p', 'p.id', '=', DB::raw('COALESCE(hr.pc_unit_id, m.pc_unit_id)'))
            ->leftJoin('assets as a', 'a.id', '=', 'm.asset_id')
            ->whereNull('m.deleted_at')
            ->where('m.status', '<>', 'cancelled')
            ->whereRaw('COALESCE(hr.replaced_at, hr.created_at) >= ?', [$report->period->startUtc()])
            ->whereRaw('COALESCE(hr.replaced_at, hr.created_at) < ?', [$report->period->endUtc()]));

        ReportScopes::location($query, 'COALESCE(p.room_id, a.current_room_id)', $report);
        $this->equipment($query, $filters, 'm');

        if ($filters->componentType !== null) {
            $query->whereRaw(ReplacementAttribution::typeSql().' = ?', [$filters->componentType]);
        }

        return $query;
    }

    public function section(OverallReportFilters $filters): ReportDocumentSection
    {
        $report = $filters->report;
        $repairs = $this->repairs($filters);

        $byClass = (clone $repairs)->groupByRaw('1')->selectRaw(MaintenanceClass::sqlCase().' as class, count(*) as n')->pluck('n', 'class');
        $totals = (clone $repairs)->selectRaw('count(*) as n,
            count(m.downtime_minutes) as downtime_n, coalesce(sum(m.downtime_minutes), 0) as downtime,
            count(m.labor_hours) as labour_n, coalesce(sum(m.labor_hours), 0) as labour,
            count(m.cost) as cost_n, coalesce(sum(m.cost), 0) as cost')->first();
        $completed = (int) ($totals->n ?? 0);

        $replacements = $this->replacements($filters);
        $replacementCount = (clone $replacements)->count();
        $failed = (clone $replacements)
            ->groupByRaw('1, 2')
            ->selectRaw(ReplacementAttribution::typeSql().' as type, '.ReplacementAttribution::basisSql().' as basis, coalesce(sum(hr.quantity), count(*)) as n')
            ->get();
        $models = (clone $replacements)
            ->leftJoin('assets as anew', 'anew.id', '=', 'hr.new_asset_id')
            ->leftJoin('hardware_models as hmn', 'hmn.id', '=', 'anew.hardware_model_id')
            ->groupByRaw('1')
            ->selectRaw('COALESCE(hmn.model_name, hcn.name) as model, coalesce(sum(hr.quantity), count(*)) as n')
            ->orderByDesc('n')
            ->limit(10)
            ->get();

        $candidates = $filters->hasEquipment() ? [] : $this->preventive->candidates($report);
        $pendingPredictions = $this->pendingPredictions($filters);

        $classRows = [];
        foreach (MaintenanceClass::cases() as $class) {
            $classRows[] = [$class->label(), (int) ($byClass[$class->value] ?? 0)];
        }

        $failedByType = [];
        $basisTotals = array_fill_keys(array_keys(ReplacementAttribution::LABELS), 0);
        foreach ($failed as $row) {
            $label = $row->type !== null ? (ComponentType::tryFrom((string) $row->type)?->label() ?? (string) $row->type) : 'Not recorded';
            $failedByType[$label] = ($failedByType[$label] ?? 0) + (int) $row->n;
            $basisTotals[(string) $row->basis] = ($basisTotals[(string) $row->basis] ?? 0) + (int) $row->n;
        }
        arsort($failedByType);

        $coverage = static fn (int $n, int $of): string => $of === 0 ? 'No completed repairs' : "Recorded on {$n} of {$of}";

        $kpis = [
            new ReportKpi('Completed repairs', number_format($completed), sprintf('%d corrective, %d preventive, %d upgrades', (int) ($byClass['corrective'] ?? 0), (int) ($byClass['preventive'] ?? 0), (int) ($byClass['upgrade'] ?? 0)), null, $completed),
            new ReportKpi('Parts replaced', number_format($replacementCount), 'On repairs not cancelled', null, $replacementCount),
            new ReportKpi('Preventive-care candidates', number_format(count($candidates)), 'PC units flagged by the rules (not AI)', null, count($candidates)),
            new ReportKpi('Downtime', number_format((int) $totals->downtime / 60, 1).' h', $coverage((int) $totals->downtime_n, $completed), null, round((int) $totals->downtime / 60, 1)),
        ];

        $tables = [
            ReportTable::make('repair_classes', 'Completed repairs by class', [['class', 'Class'], ['count', 'Completed', ReportTable::TYPE_NUMBER]], $classRows),
            ReportTable::make('repair_effort', 'Recorded effort and cost', [['figure', 'Figure'], ['value', 'Total', ReportTable::TYPE_NUMBER], ['coverage', 'Coverage']], [
                ['Downtime (hours)', number_format((int) $totals->downtime / 60, 1), $coverage((int) $totals->downtime_n, $completed)],
                ['Labour (hours)', number_format((float) $totals->labour, 1), $coverage((int) $totals->labour_n, $completed)],
                ['Recorded cost (no currency stored)', number_format((float) $totals->cost, 2), $coverage((int) $totals->cost_n, $completed)],
            ]),
            ReportTable::make('failed_parts', 'Failed parts (from replacements)', [['type', 'Part'], ['count', 'Replaced', ReportTable::TYPE_NUMBER]], array_map(static fn (string $k, int $v): array => [$k, $v], array_keys($failedByType), array_values($failedByType))),
            ReportTable::make('attribution', 'How the failed part was identified', [['basis', 'Basis'], ['count', 'Replacements', ReportTable::TYPE_NUMBER]], array_map(static fn (string $k, int $v): array => [ReplacementAttribution::LABELS[$k], $v], array_keys($basisTotals), array_values($basisTotals))),
            ReportTable::make('replaced_models', 'Parts fitted, by model', [['model', 'Model'], ['count', 'Fitted', ReportTable::TYPE_NUMBER]], $models->map(static fn (object $r): array => [(string) ($r->model ?? 'Not recorded'), (int) $r->n])->all()),
            ReportTable::make('preventive_candidates', 'Preventive-care candidates (rule-based)', [['code', 'PC unit', ReportTable::TYPE_CODE], ['room', 'Room', ReportTable::TYPE_CODE], ['rules', 'Rules'], ['evidence', 'Evidence']], array_map(static fn (array $c): array => [
                $c['code'], $c['room'] ?? '—', implode(', ', array_map(static fn (string $r): string => PreventiveMaintenanceRules::LABELS[$r], $c['rules'])), implode('; ', $c['evidence']),
            ], $candidates)),
            ReportTable::make('pending_predictions', 'Pending AI advisory findings', [['code', 'PC unit', ReportTable::TYPE_CODE], ['risk', 'Risk (model\'s own rating)'], ['issue', 'Advisory finding'], ['generated', 'Generated']], $pendingPredictions),
        ];

        $charts = [];
        $insufficient = null;
        if ($completed >= self::MIN_FOR_CHARTS) {
            [$categories, $values, $keys] = $this->overTime($repairs, $filters);
            if (count($categories) > 1) {
                $charts[] = new ChartSpec(ChartSpec::LINE, 'Completed repairs over time', $categories, [['name' => (string) $report->period->rangeLabel(), 'values' => $values, 'style' => 'current']], [ReportPalette::CURRENT], 'Completed repairs per '.$report->granularity->noun().'. The figures are in the table below.', 'repairs_over_time', 'Repairs', keys: $keys);
                $tables[] = ReportTable::make('repairs_over_time', 'Completed repairs per '.$report->granularity->noun(), [['bucket', ucfirst($report->granularity->noun())], ['count', 'Completed', ReportTable::TYPE_NUMBER]], array_map(static fn (string $c, ?int $v): array => [$c, $v ?? 'Before records'], $categories, $values));
            }
            $charts[] = new ChartSpec(ChartSpec::BAR, 'Completed repairs by class', array_map(static fn (array $r): string => (string) $r[0], $classRows), [['name' => 'Completed', 'values' => array_map(static fn (array $r): int => (int) $r[1], $classRows), 'style' => 'current']], [ReportPalette::BAR], 'Completed repairs by class. The figures are in the table below.', 'repair_classes', 'Repairs');
        } else {
            $insufficient = sprintf('Not enough recorded repairs in this period to chart: %d completed (charts start at %d), %d %s replaced. The figures and records are below.', $completed, self::MIN_FOR_CHARTS, $replacementCount, $replacementCount === 1 ? 'part' : 'parts');
        }

        if ($failedByType !== []) {
            $charts[] = new ChartSpec(ChartSpec::BAR, 'Failed parts', array_keys($failedByType), [['name' => 'Replaced', 'values' => array_values($failedByType), 'style' => 'current']], [ReportPalette::BAR], 'Parts replaced in the period, by the failed part\'s type. The figures are in the table below.', 'failed_parts', 'Parts');
        }

        $notApplicable = [];
        if ($report->hasStatusFilter() || $report->categories !== [] || $report->problemAreas !== []) {
            $notApplicable[] = 'Ticket status, category and problem area do not apply to repairs.';
        }

        return new ReportDocumentSection(
            key: OverallReportSection::Repairs->value,
            title: OverallReportSection::Repairs->label(),
            intro: OverallReportSection::Repairs->purpose(),
            kpis: $kpis,
            charts: $charts,
            tables: $tables,
            notes: [
                'Location is the repaired equipment\'s room now; maintenance records carry no room of their own.',
                'Preventive-care candidates and recurring patterns are counted by fixed rules from repair records, not by AI. AI advisory findings are listed separately and never as a probability.',
            ],
            insufficient: $insufficient,
            records: [
                ['table' => ReportRecordsTable::Repairs->value, 'title' => ReportRecordsTable::Repairs->label(), 'total' => $completed],
                ['table' => ReportRecordsTable::Replacements->value, 'title' => ReportRecordsTable::Replacements->label(), 'total' => $replacementCount],
            ],
            notApplicable: $notApplicable,
        );
    }

    /**
     * Completed repairs and the PC units flagged, for the Overview.
     *
     * @return array{completed: int, by_class: array<string, int>, replacements: int, candidates: int}
     */
    public function overviewFigures(OverallReportFilters $filters): array
    {
        $repairs = $this->repairs($filters);

        return [
            'completed' => (clone $repairs)->count(),
            'by_class' => (clone $repairs)->groupByRaw('1')->selectRaw(MaintenanceClass::sqlCase().' as class, count(*) as n')->pluck('n', 'class')->map(fn ($n): int => (int) $n)->all(),
            'replacements' => (clone $this->replacements($filters))->count(),
            'candidates' => $filters->hasEquipment() ? 0 : count($this->preventive->candidates($filters->report)),
        ];
    }

    private function equipment(Builder $query, OverallReportFilters $filters, string $alias): void
    {
        if ($filters->equipmentType === 'pc') {
            $query->where('p.uuid', $filters->equipmentUuid);
        } elseif ($filters->equipmentType === 'asset') {
            $query->where('a.uuid', $filters->equipmentUuid);
        }
    }

    /**
     * @return array{0: list<string>, 1: list<int|null>, 2: list<string>}
     */
    private function overTime(Builder $repairs, OverallReportFilters $filters): array
    {
        $report = $filters->report;
        $counts = (clone $repairs)
            ->selectRaw("to_char(date_trunc(?, m.completed_at AT TIME ZONE ?), 'YYYY-MM-DD') as bucket, count(*) as n", [$report->granularity->value, SchoolClock::TIMEZONE])
            ->groupByRaw('1')
            ->pluck('n', 'bucket');

        $start = DB::table('maintenance_records')->whereNull('deleted_at')->min('completed_at');
        $recordsStart = $start !== null ? CarbonImmutable::parse((string) $start)->setTimezone(SchoolClock::TIMEZONE) : null;
        $buckets = $report->period->buckets($report->granularity, $recordsStart);

        return [
            array_map(static fn (ReportBucket $b): string => $b->label, $buckets),
            array_map(static fn (ReportBucket $b): ?int => $b->beforeRecords ? null : (int) ($counts[$b->key] ?? 0), $buckets),
            array_map(static fn (ReportBucket $b): string => $b->key, $buckets),
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function pendingPredictions(OverallReportFilters $filters): array
    {
        $query = DB::table('ai_predictions as ap')
            ->join('pc_units as p', 'p.id', '=', 'ap.pc_unit_id')
            ->whereNull('p.deleted_at')
            ->where('ap.status', 'pending');
        ReportScopes::location($query, 'p.room_id', $filters->report);
        if ($filters->equipmentType === 'pc') {
            $query->where('p.uuid', $filters->equipmentUuid);
        } elseif ($filters->equipmentType === 'asset') {
            return [];
        }

        return $query->orderByDesc('ap.generated_at')->limit(50)
            ->get(['p.unit_code', 'ap.risk_level', 'ap.predicted_issue', 'ap.generated_at'])
            ->map(static fn (object $r): array => [
                (string) $r->unit_code,
                $r->risk_level !== null ? ucfirst((string) $r->risk_level) : 'Not rated',
                Str::limit((string) $r->predicted_issue, 120),
                $r->generated_at !== null ? CarbonImmutable::parse((string) $r->generated_at)->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d') : '',
            ])->all();
    }
}
