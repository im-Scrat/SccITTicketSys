<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Models\Ticket;
use App\Models\TicketPriority;
use Illuminate\Support\Carbon;

/**
 * Derives and re-derives a ticket's SLA deadlines from its priority
 * (SRS FR-TKT-004/017; BR-05).
 *
 * The budget lives on `ticket_priorities` as `response_time_minutes` and
 * `resolution_time_minutes`, so changing what "High" means is a settings change
 * rather than a deployment. Two consequences follow, and both are deliberate:
 *
 *  1. **Deadlines are recomputed when the priority changes.** A ticket raised as
 *     Low and escalated to Critical must inherit Critical's clock, measured from
 *     when it was *reported* — not from when someone got round to escalating it,
 *     which would reward slow triage with a longer deadline.
 *  2. **Breach is computed on read, never stored.** A stored `is_breached` flag
 *     is wrong the moment the clock passes it and nobody has run a sweep. The
 *     posture is a comparison against `now()`, so it is always current, and
 *     FR-TKT-017's *notification* is the only part that needs a scheduled job
 *     (deferred to the Notification work package).
 */
class SlaCalculator
{
    /**
     * The deadlines a ticket should carry, measured from its report time.
     *
     * @return array{response_due_at: Carbon|null, resolution_due_at: Carbon|null}
     */
    public function deadlinesFor(TicketPriority $priority, ?Carbon $reportedAt = null): array
    {
        $from = $reportedAt ?? now();

        return [
            'response_due_at' => $priority->response_time_minutes !== null
                ? $from->copy()->addMinutes($priority->response_time_minutes)
                : null,
            'resolution_due_at' => $priority->resolution_time_minutes !== null
                ? $from->copy()->addMinutes($priority->resolution_time_minutes)
                : null,
        ];
    }

    /**
     * Recompute for an existing ticket after a priority change.
     *
     * Anchored to `created_at` — the moment the fault was reported — so an
     * escalation tightens the deadline rather than resetting it.
     *
     * @return array{response_due_at: Carbon|null, resolution_due_at: Carbon|null}
     */
    public function recalculate(Ticket $ticket, TicketPriority $priority): array
    {
        return $this->deadlinesFor($priority, $ticket->created_at);
    }

    /**
     * How this ticket stands against its clock, right now.
     *
     * A terminal ticket has no live posture: once it is closed or cancelled the
     * clock is irrelevant, and reporting it as "breached" forever would make
     * every historical ticket look like a failure.
     *
     * @return array{
     *     response_breached: bool,
     *     resolution_breached: bool,
     *     breached: bool,
     *     at_risk: bool,
     *     minutes_to_resolution: int|null
     * }
     */
    public function posture(Ticket $ticket, int $atRiskLeadHours = 4): array
    {
        $ticket->loadMissing('status');

        $live = $ticket->status !== null
            && ! $ticket->status->is_terminal
            && $ticket->resolved_at === null;

        if (! $live) {
            return [
                'response_breached' => false,
                'resolution_breached' => false,
                'breached' => false,
                'at_risk' => false,
                'minutes_to_resolution' => null,
            ];
        }

        $now = now();

        // A response deadline is met by the first technician/administrator
        // response, so an answered ticket cannot breach it retroactively.
        $responseBreached = $ticket->response_due_at !== null
            && $ticket->first_response_at === null
            && $ticket->response_due_at->isBefore($now);

        $resolutionBreached = $ticket->resolution_due_at !== null
            && $ticket->resolution_due_at->isBefore($now);

        $minutesToResolution = $ticket->resolution_due_at !== null
            ? (int) $now->diffInMinutes($ticket->resolution_due_at, false)
            : null;

        return [
            'response_breached' => $responseBreached,
            'resolution_breached' => $resolutionBreached,
            'breached' => $responseBreached || $resolutionBreached,
            'at_risk' => ! $resolutionBreached
                && $minutesToResolution !== null
                && $minutesToResolution >= 0
                && $minutesToResolution <= $atRiskLeadHours * 60,
            'minutes_to_resolution' => $minutesToResolution,
        ];
    }
}
