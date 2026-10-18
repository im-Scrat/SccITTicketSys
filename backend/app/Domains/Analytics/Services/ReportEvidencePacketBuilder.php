<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportEvidencePacket;
use App\Domains\Analytics\DTOs\ReportFact;
use App\Domains\Maintenance\Services\ReplacementAttribution;
use App\Enums\ComponentType;
use App\Enums\MaintenanceClass;
use App\Enums\ReportQuestion;
use App\Support\SchoolClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The facts an AI interpretation of the overall report may use (Phase 2.10
 * R5, SDD DD-95), computed from the same populations as the report sections,
 * with a **sufficiency gate per question** decided here — below it, the
 * application says "not enough data" and no provider is called.
 *
 * | Question | Sufficient when |
 * |---|---|
 * | q1 replacement drivers | at least one PC unit with 2+ corrective replacement events |
 * | q2 problem locations | 5+ located PC problems across 2+ rooms |
 * | q3 failing components | 3+ corrective replacement events with a known part type |
 * | q4 performance issues | the same PC and category reported twice, or 5+ such tickets |
 * | q5 preventive candidates | at least one PC flagged by a *history* rule (R1/R2) |
 *
 * Problems exclude duplicates and cancelled tickets. Equipment and rooms are
 * named by their codes only; nothing personal, no free text, no costs.
 */
final class ReportEvidencePacketBuilder
{
    /** Ticket categories counted as performance problems (plan decision 9.1-5). */
    public const PERFORMANCE_CATEGORIES = ['performance-slowness', 'blue-screen', 'os-updates', 'bios-firmware'];

    private const TOP = 5;

    public function __construct(
        private readonly MaintenanceReportQuery $maintenance,
        private readonly PreventiveMaintenanceRules $preventive,
    ) {}

    public function build(OverallReportFilters $filters): ReportEvidencePacket
    {
        $report = $filters->report;
        $corrective = $this->correctiveReplacements($filters);

        return new ReportEvidencePacket(
            questions: [
                ReportQuestion::ReplacementDrivers->value => $this->q1($corrective),
                ReportQuestion::ProblemLocations->value => $this->q2($filters),
                ReportQuestion::FailingComponents->value => $this->q3($corrective),
                ReportQuestion::PerformanceIssues->value => $this->q4($filters),
                ReportQuestion::PreventiveCandidates->value => $this->q5($filters),
            ],
            scope: $this->scope($filters),
            limitations: [
                'Counts come from recorded tickets and completed repairs only; work that was never recorded is not in them.',
                'A failed part is the removed part when it was recorded, otherwise inferred from the part fitted.',
                'PC problems are counted in the room they were reported in; repairs in the equipment\'s room now.',
                'An absence of history is not evidence of low risk.',
                'Records begin when this system started being used; earlier periods have no data.',
            ],
        );
    }

    /**
     * Corrective replacement events in scope: one row per replacement, with
     * the PC code and the failed part's type (attribution as in the report).
     *
     * @return list<object>
     */
    private function correctiveReplacements(OverallReportFilters $filters): array
    {
        return $this->maintenance->replacements($filters)
            ->join('maintenance_types as mt', 'mt.id', '=', 'm.maintenance_type_id')
            ->whereRaw(MaintenanceClass::sqlCase().' = ?', ['corrective'])
            ->whereNotNull('p.id')
            ->orderBy('p.unit_code')
            ->get(['p.unit_code as code', DB::raw(ReplacementAttribution::typeSql().' as type'), DB::raw('m.id as record_id')])
            ->all();
    }

    /**
     * @param  list<object>  $events
     * @return array{sufficient: bool, reason: string|null, facts: list<ReportFact>}
     */
    private function q1(array $events): array
    {
        $q = ReportQuestion::ReplacementDrivers->value;
        $byPc = [];
        foreach ($events as $e) {
            $byPc[(string) $e->code][] = $e;
        }
        uasort($byPc, static fn (array $a, array $b): int => count($b) <=> count($a));

        $facts = [];
        $i = 0;
        foreach (array_slice($byPc, 0, self::TOP, true) as $code => $rows) {
            $i++;
            $types = [];
            foreach ($rows as $r) {
                $label = $r->type !== null ? (ComponentType::tryFrom((string) $r->type)?->label() ?? (string) $r->type) : 'Not recorded';
                $types[$label] = ($types[$label] ?? 0) + 1;
            }
            arsort($types);
            $records = count(array_unique(array_map(static fn (object $r): int => (int) $r->record_id, $rows)));
            $facts[] = new ReportFact("{$q}.pc_{$i}.corrective_replacement_events", $q, "Corrective part replacements on {$code}", count($rows), 'replacements', 'Parts replaced on corrective repairs completed in the period', $records);
            $facts[] = new ReportFact("{$q}.pc_{$i}.most_replaced_part", $q, "Most replaced part on {$code}", (string) array_key_first($types), null, 'Failed part by the report\'s attribution rule', count($rows));
        }

        $qualifying = count(array_filter($byPc, static fn (array $rows): bool => count($rows) >= 2));
        $facts[] = new ReportFact("{$q}.pcs_with_two_or_more", $q, 'PC units with two or more corrective replacements', $qualifying, 'PC units', 'Count of PC units', count($events));

        return [
            'sufficient' => $qualifying >= 1,
            'reason' => $qualifying >= 1 ? null : 'No PC unit had two or more corrective part replacements in this period.',
            'facts' => $facts,
        ];
    }

    /**
     * @return array{sufficient: bool, reason: string|null, facts: list<ReportFact>}
     */
    private function q2(OverallReportFilters $filters): array
    {
        $q = ReportQuestion::ProblemLocations->value;
        $rows = $this->problems($filters)
            ->whereNotNull('t.pc_unit_id')
            ->join('rooms as r', 'r.id', '=', 't.room_id')
            ->groupBy('r.code')
            ->orderByDesc(DB::raw('count(*)'))->orderBy('r.code')
            ->get(['r.code', DB::raw('count(*) as n'), DB::raw('count(distinct t.pc_unit_id) as pcs')]);
        $located = (int) $rows->sum('n');

        $facts = [];
        foreach ($rows->take(self::TOP)->values() as $i => $r) {
            $n = $i + 1;
            $facts[] = new ReportFact("{$q}.room_{$n}.pc_problems", $q, "PC problems reported in {$r->code}", (int) $r->n, 'problems', 'Tickets on a PC unit, no duplicates, nothing cancelled', (int) $r->n);
            $facts[] = new ReportFact("{$q}.room_{$n}.pcs_affected", $q, "PC units with a problem in {$r->code}", (int) $r->pcs, 'PC units', 'Distinct PC units among those tickets', (int) $r->n);
        }
        $facts[] = new ReportFact("{$q}.located_pc_problems", $q, 'PC problems with a recorded room', $located, 'problems', 'Tickets on a PC unit in a recorded room', $located);
        $facts[] = new ReportFact("{$q}.rooms_with_pc_problems", $q, 'Rooms with PC problems', $rows->count(), 'rooms', 'Count of rooms', $located);

        $ok = $located >= 5 && $rows->count() >= 2;

        return ['sufficient' => $ok, 'reason' => $ok ? null : sprintf('%d located PC problems in %d rooms; at least 5 across 2 rooms are needed.', $located, $rows->count()), 'facts' => $facts];
    }

    /**
     * @param  list<object>  $events
     * @return array{sufficient: bool, reason: string|null, facts: list<ReportFact>}
     */
    private function q3(array $events): array
    {
        $q = ReportQuestion::FailingComponents->value;
        $byType = [];
        foreach ($events as $e) {
            if ($e->type !== null) {
                $byType[(string) $e->type] = ($byType[(string) $e->type] ?? 0) + 1;
            }
        }
        arsort($byType);
        $known = array_sum($byType);

        $facts = [];
        foreach (array_slice($byType, 0, self::TOP, true) as $type => $n) {
            $facts[] = new ReportFact("{$q}.{$type}.corrective_replacement_events", $q, 'Corrective replacements of '.(ComponentType::tryFrom($type)?->label() ?? $type), $n, 'replacements', 'Failed part by the report\'s attribution rule', $n);
        }
        $facts[] = new ReportFact("{$q}.known_type_events", $q, 'Corrective replacements with a known part type', $known, 'replacements', 'Count of replacements', $known);

        return ['sufficient' => $known >= 3, 'reason' => $known >= 3 ? null : "{$known} corrective replacements with a known part type; at least 3 are needed.", 'facts' => $facts];
    }

    /**
     * @return array{sufficient: bool, reason: string|null, facts: list<ReportFact>}
     */
    private function q4(OverallReportFilters $filters): array
    {
        $q = ReportQuestion::PerformanceIssues->value;
        $base = $this->problems($filters)
            ->join('ticket_categories as c', 'c.id', '=', 't.category_id')
            ->whereIn('c.slug', self::PERFORMANCE_CATEGORIES);

        $byCategory = (clone $base)->groupBy('c.slug')->orderBy('c.slug')->get(['c.slug', DB::raw('count(*) as n')]);
        $repeats = (clone $base)->join('pc_units as p', 'p.id', '=', 't.pc_unit_id')
            ->groupBy('p.unit_code', 'c.slug')->havingRaw('count(*) >= 2')
            ->orderByDesc(DB::raw('count(*)'))->orderBy('p.unit_code')
            ->get(['p.unit_code as code', 'c.slug', DB::raw('count(*) as n')]);
        $total = (int) $byCategory->sum('n');

        $facts = [];
        foreach ($byCategory as $r) {
            $facts[] = new ReportFact("{$q}.{$r->slug}.tickets", $q, "Problems in category {$r->slug}", (int) $r->n, 'problems', 'No duplicates, nothing cancelled', (int) $r->n);
        }
        foreach ($repeats->take(self::TOP)->values() as $i => $r) {
            $n = $i + 1;
            $facts[] = new ReportFact("{$q}.pc_{$n}.repeat_reports", $q, "Repeated {$r->slug} problems on {$r->code}", (int) $r->n, 'problems', 'Same PC unit and category, reported more than once', (int) $r->n);
        }
        $facts[] = new ReportFact("{$q}.performance_problems", $q, 'Performance problems in total', $total, 'problems', 'Categories: '.implode(', ', self::PERFORMANCE_CATEGORIES), $total);

        $ok = $repeats->isNotEmpty() || $total >= 5;

        return ['sufficient' => $ok, 'reason' => $ok ? null : "{$total} performance problems and no PC unit with a repeated one; 5 problems or a repeat are needed.", 'facts' => $facts];
    }

    /**
     * @return array{sufficient: bool, reason: string|null, facts: list<ReportFact>}
     */
    private function q5(OverallReportFilters $filters): array
    {
        $q = ReportQuestion::PreventiveCandidates->value;
        $candidates = $filters->hasEquipment() ? [] : $this->preventive->candidates($filters->report);
        $history = array_values(array_filter($candidates, static fn (array $c): bool => array_intersect($c['rules'], [PreventiveMaintenanceRules::R1, PreventiveMaintenanceRules::R2]) !== []));
        $intervalOnly = count($candidates) - count($history);

        $facts = [];
        foreach (array_slice($history, 0, self::TOP) as $i => $c) {
            $n = $i + 1;
            $facts[] = new ReportFact("{$q}.pc_{$n}.rules", $q, "Rules that flagged {$c['code']}", implode(', ', array_map(static fn (string $r): string => PreventiveMaintenanceRules::LABELS[$r], $c['rules'])), null, 'Rule-based preventive-care flags', count($c['evidence']));
            $facts[] = new ReportFact("{$q}.pc_{$n}.evidence_items", $q, "Evidence items for {$c['code']}", count($c['evidence']), 'items', 'Detected patterns and visit gaps', count($c['evidence']));
        }
        $facts[] = new ReportFact("{$q}.flagged_by_history", $q, 'PC units flagged by a repair-history rule', count($history), 'PC units', 'Rules R1 (recurring fault) and R2 (frequent repairs)', count($history));
        $facts[] = new ReportFact("{$q}.flagged_by_interval_only", $q, 'PC units flagged only for a missed preventive visit', $intervalOnly, 'PC units', 'Rule R3; stated by the application, not interpreted', $intervalOnly);

        return ['sufficient' => $history !== [], 'reason' => $history !== [] ? null : 'No PC unit was flagged by a repair-history rule.', 'facts' => $facts];
    }

    /** Problems (no duplicates, nothing cancelled) reported in the period, in scope. */
    private function problems(OverallReportFilters $filters): Builder
    {
        $report = $filters->report;
        $query = DB::table('tickets as t')
            ->whereNull('t.deleted_at')
            ->whereNull('t.duplicate_of_id')
            ->whereNotIn('t.current_status_id', DB::table('ticket_statuses')->select('id')->where('slug', 'cancelled'))
            ->where('t.created_at', '>=', $report->period->startUtc())
            ->where('t.created_at', '<', $report->period->endUtc());
        ReportScopes::location($query, 't.room_id', $report);
        ReportScopes::ticketCategory($query, $report);

        if ($filters->equipmentType === 'pc') {
            $query->whereIn('t.pc_unit_id', DB::table('pc_units')->select('id')->where('uuid', $filters->equipmentUuid));
        } elseif ($filters->equipmentType === 'asset') {
            $query->whereIn('t.asset_id', DB::table('assets')->select('id')->where('uuid', $filters->equipmentUuid));
        }

        return $query;
    }

    /** The scope in codes and enums only — never a name or free text. */
    private function scope(OverallReportFilters $filters): string
    {
        $report = $filters->report;
        $parts = [sprintf('Period: %s to %s (%s)', $report->period->fromDate(), $report->period->toDateInclusive(), SchoolClock::TIMEZONE)];

        if ($report->room !== null) {
            $parts[] = 'Room: '.(string) DB::table('rooms')->where('uuid', $report->room)->value('code');
        } elseif ($report->building !== null) {
            $parts[] = 'Building: '.(string) DB::table('buildings')->where('uuid', $report->building)->value('code');
        }
        if ($report->roomType !== null) {
            $parts[] = 'Room type: '.$report->roomType;
        }
        if ($report->problemAreas !== []) {
            $parts[] = 'Problem areas: '.implode(', ', $report->problemAreas);
        }
        if ($report->categories !== []) {
            $parts[] = 'Categories: '.implode(', ', $report->categories);
        }
        if ($filters->equipmentType === 'pc') {
            $parts[] = 'PC unit: '.(string) DB::table('pc_units')->where('uuid', $filters->equipmentUuid)->value('unit_code');
        }

        return implode('; ', $parts);
    }
}
