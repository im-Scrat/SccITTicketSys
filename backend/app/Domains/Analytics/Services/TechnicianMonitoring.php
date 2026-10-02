<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.9 — what the system actually knows about each technician's work,
 * for administrators (see TechnicianMonitoringAccess).
 *
 * Every figure is one of two kinds, and the payload says which:
 *
 *   recorded    read straight from a row — assignment counts by status,
 *               completed maintenance visits, the latest timestamp of either.
 *   calculated  derived from recorded timestamps — the median time from
 *               assignment to accept / start / complete, and the SLA posture
 *               of the technician's open tickets.
 *
 * There is deliberately **no availability, presence or "on duty" figure**:
 * nothing in the schema records one, and inventing it from activity would
 * present a guess as a fact. Composes TicketMetrics rather than repeating it.
 */
class TechnicianMonitoring
{
    public function __construct(private readonly TicketMetrics $tickets) {}

    /**
     * Every technician account (not deleted), each with its figures.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $technicians = $this->technicians()->orderBy('last_name')->orderBy('first_name')->get();
        $medians = $this->medians($technicians->modelKeys());

        return $technicians
            ->map(fn (User $technician): array => $this->summary($technician, $medians[$technician->getKey()] ?? []))
            ->values()
            ->all();
    }

    /**
     * One technician in detail: the overview figures plus the active work list.
     *
     * @return array<string, mixed>
     */
    public function detail(User $technician): array
    {
        $medians = $this->medians([$technician->getKey()]);

        return [
            ...$this->summary($technician, $medians[$technician->getKey()] ?? []),
            'active_assignments' => $this->tickets->activeAssignments($technician, 20),
        ];
    }

    /** @return Builder<User> */
    public function technicians(): Builder
    {
        return User::query()->whereIn('role_id', Role::query()->select('id')->where('slug', 'technician'));
    }

    /**
     * @param  array<string, float|null>  $medians
     * @return array<string, mixed>
     */
    private function summary(User $technician, array $medians): array
    {
        $load = $this->tickets->assignmentLoad($technician);
        $posture = $this->tickets->slaPosture(technician: $technician);

        return [
            'id' => $technician->uuid,
            'name' => $technician->fullName(),
            'account_status' => $technician->status->value,
            'recorded' => [
                'active_assignments' => $load['active'],
                'pending' => $load['pending'],
                'in_progress' => $load['in_progress'],
                'on_hold' => $load['on_hold'],
                'completed_assignments' => $this->assignmentCount($technician, AssignmentStatus::Completed),
                'declined_assignments' => $this->assignmentCount($technician, AssignmentStatus::Declined),
                'completed_maintenance' => DB::table('maintenance_records')
                    ->where('technician_id', $technician->getKey())
                    ->where('status', MaintenanceStatus::Completed->value)
                    ->whereNull('deleted_at')
                    ->count(),
                'last_activity_at' => $this->lastActivity($technician),
            ],
            'calculated' => [
                'median_minutes_to_accept' => $medians['accept'] ?? null,
                'median_minutes_to_start' => $medians['start'] ?? null,
                'median_minutes_to_complete' => $medians['complete'] ?? null,
                'sla_breached' => $posture['breached'],
                'sla_at_risk' => $posture['at_risk'],
            ],
        ];
    }

    private function assignmentCount(User $technician, AssignmentStatus $status): int
    {
        return DB::table('technician_assignments')
            ->where('technician_id', $technician->getKey())
            ->where('status', $status->value)
            ->count();
    }

    /** The latest recorded touch on an assignment or a maintenance visit. */
    private function lastActivity(User $technician): ?string
    {
        $assignment = DB::table('technician_assignments')->where('technician_id', $technician->getKey())->max('updated_at');
        $maintenance = DB::table('maintenance_records')->where('technician_id', $technician->getKey())->whereNull('deleted_at')->max('updated_at');

        $latest = max((string) $assignment, (string) $maintenance);

        return $latest !== '' ? Carbon::parse($latest)->toIso8601String() : null;
    }

    /**
     * Median minutes from `assigned_at` to accept / start / complete, per
     * technician, over every assignment that reached that point. A technician
     * with no such assignments gets null, not zero — no data is not "instant".
     *
     * @param  array<int, mixed>  $technicianIds
     * @return array<int, array{accept: float|null, start: float|null, complete: float|null}>
     */
    private function medians(array $technicianIds): array
    {
        if ($technicianIds === []) {
            return [];
        }

        $median = static fn (string $column): string => "percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch from ({$column} - assigned_at)) / 60) FILTER (WHERE {$column} IS NOT NULL AND assigned_at IS NOT NULL AND {$column} >= assigned_at)";

        $rows = DB::table('technician_assignments')
            ->whereIn('technician_id', array_values($technicianIds))
            ->groupBy('technician_id')
            ->select([
                'technician_id',
                DB::raw($median('accepted_at').' as accept'),
                DB::raw($median('started_at').' as start'),
                DB::raw($median('completed_at').' as complete'),
            ])
            ->get();

        $round = static fn (mixed $value): ?float => $value !== null ? round((float) $value, 1) : null;

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->technician_id] = [
                'accept' => $round($row->accept),
                'start' => $round($row->start),
                'complete' => $round($row->complete),
            ];
        }

        return $result;
    }
}
