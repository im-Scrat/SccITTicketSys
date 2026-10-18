<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\KnowledgeBase\DTOs\DetectedPattern;
use App\Domains\KnowledgeBase\Services\FailurePatternDetector;
use App\Domains\KnowledgeBase\Services\PcMaintenanceHistory;
use App\Domains\Maintenance\Services\MaintenanceMetrics;
use App\Enums\MaintenanceClass;
use App\Models\PcUnit;
use App\Support\SchoolClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PC units that are candidates for preventive care (Phase 2.10 R4, SDD DD-94).
 * **Rule-based, never AI**, with the evidence for each flag:
 *
 *  - **R1 recurring fault** — the deterministic `FailurePatternDetector` finds
 *    the same component replaced, or the same fault category, in two or more
 *    corrective repairs;
 *  - **R2 frequent repairs** — three or more corrective repairs within a year
 *    (the detector's frequency rule);
 *  - **R3 no preventive visit** — no completed preventive visit within the
 *    configured interval (`maintenance.default_interval_days`, 90 by default,
 *    read through `MaintenanceMetrics::cadence()`). The clock starts at the later
 *    of the PC's registration and the first maintenance record ever kept, so a
 *    PC is never flagged for a period before records began.
 *
 * Retired PC units are not candidates. "As of" is bound once, never `now()` in
 * SQL. The location filter applies (the PC's room now); the period does not —
 * a candidate is about the PC's whole recorded history.
 */
final class PreventiveMaintenanceRules
{
    public const R1 = 'recurring_fault';

    public const R2 = 'frequent_repairs';

    public const R3 = 'no_preventive_visit';

    public const LABELS = [
        self::R1 => 'Recurring fault',
        self::R2 => 'Frequent repairs',
        self::R3 => 'No preventive visit',
    ];

    public function __construct(
        private readonly FailurePatternDetector $detector,
        private readonly PcMaintenanceHistory $history,
        private readonly MaintenanceMetrics $maintenance,
    ) {}

    /**
     * @return list<array{uuid: string, code: string, name: string, room: string|null, rules: list<string>, evidence: list<string>}>
     */
    public function candidates(ReportFilters $filters, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= SchoolClock::now();
        $interval = (int) $this->maintenance->cadence()['interval_days'];
        $recordsStart = DB::table('maintenance_records')->whereNull('deleted_at')->min('created_at');

        $pcs = ReportScopes::location(DB::table('pc_units as p')->whereNull('p.deleted_at')->where('p.status', '<>', 'retired'), 'p.room_id', $filters)
            ->leftJoin('rooms as r', 'r.id', '=', 'p.room_id')
            ->orderBy('p.unit_code')
            ->get(['p.id', 'p.uuid', 'p.unit_code', 'p.pc_name', 'p.created_at', 'r.code as room_code']);

        if ($pcs->isEmpty()) {
            return [];
        }

        $ids = $pcs->pluck('id')->all();
        $corrective = DB::table('maintenance_records as m')
            ->join('maintenance_types as mt', 'mt.id', '=', 'm.maintenance_type_id')
            ->whereNull('m.deleted_at')->where('m.status', 'completed')
            ->whereIn('m.pc_unit_id', $ids)
            ->whereRaw(MaintenanceClass::sqlCase().' = ?', ['corrective'])
            ->groupBy('m.pc_unit_id')->selectRaw('m.pc_unit_id as k, count(*) as n')->pluck('n', 'k');
        $lastPreventive = DB::table('maintenance_records as m')
            ->join('maintenance_types as mt', 'mt.id', '=', 'm.maintenance_type_id')
            ->whereNull('m.deleted_at')->where('m.status', 'completed')->where('mt.is_preventive', true)
            ->whereIn('m.pc_unit_id', $ids)
            ->groupBy('m.pc_unit_id')->selectRaw('m.pc_unit_id as k, max(m.completed_at) as n')->pluck('n', 'k');

        $out = [];
        foreach ($pcs as $pc) {
            $rules = [];
            $evidence = [];

            if ((int) ($corrective[$pc->id] ?? 0) >= FailurePatternDetector::MIN_COMPONENT_RECURRENCE) {
                $model = PcUnit::query()->find($pc->id);
                foreach ($model !== null ? $this->detector->detect($this->history->for($model), $asOf) : [] as $pattern) {
                    $rule = $pattern->kind === DetectedPattern::KIND_REPAIR_FREQUENCY ? self::R2 : self::R1;
                    $rules[$rule] = true;
                    $evidence[] = sprintf('%s: %d times, latest %s', $pattern->name, $pattern->occurrenceCount, $pattern->lastAt->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d'));
                }
            }

            $start = CarbonImmutable::parse((string) $pc->created_at);
            if ($recordsStart !== null && CarbonImmutable::parse((string) $recordsStart)->greaterThan($start)) {
                $start = CarbonImmutable::parse((string) $recordsStart);
            }
            $last = isset($lastPreventive[$pc->id]) ? CarbonImmutable::parse((string) $lastPreventive[$pc->id]) : null;
            $due = ($last ?? $start)->addDays($interval);
            if ($start->addDays($interval)->lessThanOrEqualTo($asOf) && $due->lessThanOrEqualTo($asOf)) {
                $rules[self::R3] = true;
                $evidence[] = $last !== null
                    ? sprintf('Last preventive visit %s, more than %d days ago', $last->setTimezone(SchoolClock::TIMEZONE)->format('Y-m-d'), $interval)
                    : sprintf('No preventive visit recorded in %d days of records', (int) $start->diffInDays($asOf));
            }

            if ($rules !== []) {
                $out[] = [
                    'uuid' => (string) $pc->uuid,
                    'code' => (string) $pc->unit_code,
                    'name' => (string) $pc->pc_name,
                    'room' => $pc->room_code !== null ? (string) $pc->room_code : null,
                    'rules' => array_keys($rules),
                    'evidence' => $evidence,
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => [count($b['rules']), $a['code']] <=> [count($a['rules']), $b['code']]);

        return $out;
    }
}
