<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Enums\AssignmentStatus;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service-desk aggregates for the role dashboards (SRS FR-DSH-003/004).
 *
 * Every figure is a database rollup — `count(*) filter (...)`, grouped counts, or
 * a bounded `limit`ed list — never a collection walked in PHP (FR-DSH-005,
 * NFR-PERF-008). Open/terminal semantics come from `ticket_statuses.is_open` /
 * `is_terminal` rather than hard-coded status names, so the configurable status
 * set stays authoritative (FR-TKT-005).
 */
class TicketMetrics
{
    /** Open backlog across the platform. */
    public function openBacklog(): int
    {
        return $this->openTickets()->count();
    }

    /**
     * Live ticket counts per status, ordered by the configured sort order and
     * zero-filled so the distribution always shows the full configured set.
     *
     * @return list<array{key: string, label: string, count: int, color: string|null, is_open: bool}>
     */
    public function byStatus(): array
    {
        $counts = DB::table('tickets')
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.current_status_id')
            ->whereNull('tickets.deleted_at')
            ->groupBy('ticket_statuses.slug')
            ->select('ticket_statuses.slug', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'slug');

        /** @var Collection<int, object> $statuses */
        $statuses = DB::table('ticket_statuses')
            ->orderBy('sort_order')
            ->select(['slug', 'name', 'color', 'is_open'])
            ->get();

        return $statuses->map(fn (object $status): array => [
            'key' => (string) $status->slug,
            'label' => (string) $status->name,
            'count' => (int) ($counts[$status->slug] ?? 0),
            'color' => $status->color !== null ? (string) $status->color : null,
            'is_open' => (bool) $status->is_open,
        ])->all();
    }

    /**
     * Open ticket counts per priority, highest severity first, zero-filled.
     *
     * @return list<array{key: string, label: string, count: int, color: string|null}>
     */
    public function openByPriority(): array
    {
        $counts = DB::table('tickets')
            ->join('ticket_priorities', 'ticket_priorities.id', '=', 'tickets.priority_id')
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.current_status_id')
            ->whereNull('tickets.deleted_at')
            ->where('ticket_statuses.is_open', true)
            ->groupBy('ticket_priorities.slug')
            ->select('ticket_priorities.slug', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'slug');

        /** @var Collection<int, object> $priorities */
        $priorities = DB::table('ticket_priorities')
            ->orderByDesc('level')
            ->select(['slug', 'name', 'color'])
            ->get();

        return $priorities->map(fn (object $priority): array => [
            'key' => (string) $priority->slug,
            'label' => (string) $priority->name,
            'count' => (int) ($counts[$priority->slug] ?? 0),
            'color' => $priority->color !== null ? (string) $priority->color : null,
        ])->all();
    }

    /**
     * SLA posture over non-terminal tickets (FR-TKT-017): already past a due
     * time versus due within the lead window.
     *
     * @return array{breached: int, at_risk: int, lead_hours: int}
     */
    public function slaPosture(int $leadHours = 4, ?User $technician = null): array
    {
        $now = now();
        $threshold = $now->copy()->addHours($leadHours);

        $base = fn (): Builder => $this->nonTerminalTickets($technician);

        return [
            // The OR pair is wrapped so it cannot escape the non-terminal scope.
            'breached' => $base()
                ->where(function (Builder $query) use ($now): void {
                    $query->where(function (Builder $response) use ($now): void {
                        $response->where('response_due_at', '<', $now)
                            ->whereNull('first_response_at');
                    })->orWhere(function (Builder $resolution) use ($now): void {
                        $resolution->where('resolution_due_at', '<', $now)
                            ->whereNull('resolved_at');
                    });
                })
                ->count(),
            'at_risk' => $base()
                ->whereNull('resolved_at')
                ->whereNotNull('resolution_due_at')
                ->whereBetween('resolution_due_at', [$now, $threshold])
                ->count(),
            'lead_hours' => $leadHours,
        ];
    }

    /** Open tickets with no technician assigned. */
    public function unassignedOpen(): int
    {
        return $this->openTickets()->whereNull('assigned_technician_id')->count();
    }

    /**
     * Open workload per technician, busiest first.
     *
     * @return list<array{id: string, name: string, count: int}>
     */
    public function technicianWorkload(int $limit = 5): array
    {
        /** @var Collection<int, object> $rows */
        $rows = DB::table('tickets')
            ->join('users', 'users.id', '=', 'tickets.assigned_technician_id')
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.current_status_id')
            ->whereNull('tickets.deleted_at')
            ->where('ticket_statuses.is_open', true)
            ->groupBy('users.id', 'users.uuid', 'users.first_name', 'users.last_name')
            ->orderByDesc(DB::raw('count(tickets.id)'))
            ->limit($limit)
            ->select([
                'users.uuid',
                'users.first_name',
                'users.last_name',
                DB::raw('count(tickets.id) as aggregate'),
            ])
            ->get();

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->uuid,
            'name' => trim($row->first_name.' '.$row->last_name),
            'count' => (int) $row->aggregate,
        ])->all();
    }

    /** Open tickets currently assigned to this technician. */
    public function openAssignedTo(User $technician): int
    {
        return $this->openTickets()->where('assigned_technician_id', $technician->getKey())->count();
    }

    /**
     * The technician's live assignment lifecycle counts (FR-ASN-002/006).
     *
     * @return array{active: int, pending: int, in_progress: int, on_hold: int}
     */
    public function assignmentLoad(User $technician): array
    {
        // One grouped rollup (at most one row per status) — the active total is
        // summed from the enum's own definition of "active" rather than a status
        // list duplicated in SQL.
        $counts = DB::table('technician_assignments')
            ->where('technician_id', $technician->getKey())
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->pluck('aggregate', 'status');

        $active = 0;

        foreach (AssignmentStatus::activeValues() as $status) {
            $active += (int) ($counts[$status] ?? 0);
        }

        return [
            'active' => $active,
            'pending' => (int) ($counts[AssignmentStatus::Pending->value] ?? 0),
            'in_progress' => (int) ($counts[AssignmentStatus::InProgress->value] ?? 0),
            'on_hold' => (int) ($counts[AssignmentStatus::OnHold->value] ?? 0),
        ];
    }

    /**
     * The reporter's own request posture (Teacher dashboard).
     *
     * @return array{open: int, awaiting_confirmation: int, resolved_recently: int, total: int}
     */
    public function reporterSummary(User $reporter, int $recentDays = 30): array
    {
        $mine = fn (): Builder => Ticket::query()->where('reporter_id', $reporter->getKey());

        return [
            'open' => $mine()->whereHas('status', fn (Builder $q): Builder => $q->where('is_open', true))->count(),
            // Resolved but not yet closed — the reporter is asked to confirm (FR-TKT-016).
            'awaiting_confirmation' => $mine()
                ->whereNotNull('resolved_at')
                ->whereNull('closed_at')
                ->whereHas('status', fn (Builder $q): Builder => $q->where('is_terminal', false))
                ->count(),
            'resolved_recently' => $mine()
                ->where('resolved_at', '>=', now()->subDays($recentDays))
                ->count(),
            'total' => $mine()->count(),
        ];
    }

    /**
     * A short, bounded ticket list for a dashboard panel.
     *
     * @param  'reporter'|'technician'|'unassigned'  $scope
     * @return list<array<string, mixed>>
     */
    public function recentTickets(string $scope, ?User $user = null, int $limit = 5): array
    {
        $query = Ticket::query()->with(['status', 'priority', 'room']);

        match ($scope) {
            'reporter' => $query->where('reporter_id', $user?->getKey()),
            'technician' => $query->where('assigned_technician_id', $user?->getKey())
                ->whereHas('status', fn (Builder $q): Builder => $q->where('is_open', true)),
            'unassigned' => $query->whereNull('assigned_technician_id')
                ->whereHas('status', fn (Builder $q): Builder => $q->where('is_open', true)),
        };

        return $query
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => $ticket->uuid,
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'status' => [
                    'label' => $ticket->status?->name,
                    'is_open' => (bool) $ticket->status?->is_open,
                ],
                'priority' => $ticket->priority?->name,
                'room' => $ticket->room?->name,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The technician's active assignments as a bounded list (FR-ASN-006).
     *
     * `technician_assignments` carries no public uuid of its own — assignments
     * become addressable with the Tickets domain — so each row is keyed by the
     * ticket it concerns, which is what the dashboard links to anyway.
     *
     * @return list<array<string, mixed>>
     */
    public function activeAssignments(User $technician, int $limit = 5): array
    {
        return TechnicianAssignment::query()
            ->with(['ticket.status', 'ticket.priority'])
            ->where('technician_id', $technician->getKey())
            ->whereIn('status', AssignmentStatus::activeValues())
            ->latest('assigned_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (TechnicianAssignment $assignment): array => [
                'id' => $assignment->ticket?->uuid,
                'assignment_status' => $assignment->status->value,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'number' => $assignment->ticket?->ticket_number,
                'title' => $assignment->ticket?->title,
                'priority' => $assignment->ticket?->priority?->name,
                'status' => [
                    'label' => $assignment->ticket?->status?->name,
                    'is_open' => (bool) $assignment->ticket?->status?->is_open,
                ],
                'resolution_due_at' => $assignment->ticket?->resolution_due_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Tickets in an open status.
     *
     * @return Builder<Ticket>
     */
    private function openTickets(): Builder
    {
        return Ticket::query()->whereHas('status', fn (Builder $q): Builder => $q->where('is_open', true));
    }

    /**
     * Tickets not in a terminal status — the population an SLA can still bind.
     *
     * @return Builder<Ticket>
     */
    private function nonTerminalTickets(?User $technician = null): Builder
    {
        $query = Ticket::query()
            ->whereHas('status', fn (Builder $q): Builder => $q->where('is_terminal', false));

        if ($technician !== null) {
            $query->where('assigned_technician_id', $technician->getKey());
        }

        return $query;
    }
}
