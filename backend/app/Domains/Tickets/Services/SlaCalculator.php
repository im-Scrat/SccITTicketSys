<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Models\Ticket;
use App\Models\TicketPriority;
use Illuminate\Database\Eloquent\Builder;
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
 *     Low and escalated to Urgent must inherit Urgent's clock, measured from
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
     * How far ahead "due soon" looks. The dashboard's "Due within 4 h" card and
     * the list it opens read the same constant, so the label cannot drift from
     * the window.
     */
    public const AT_RISK_LEAD_HOURS = 4;

    /*
     * The three SQL predicates below are the single definition of "live",
     * "overdue" and "due soon" for every *aggregate* and *list* in the system:
     * the dashboards' counts (`TicketMetrics::slaPosture()`), the technician
     * queue's `breached` / `due_soon` filters and the administrator directory's
     * `breached` filter. A figure and the list it opens are only the same set if
     * they are written once — which is why they live here rather than being
     * copied beside each query. `posture()` below is the same rule evaluated on
     * one loaded ticket.
     */

    /**
     * Tickets not in a terminal status — the population an SLA can still bind.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function constrainLive(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q): Builder => $q->where('is_terminal', false));
    }

    /**
     * Tickets that have missed a deadline (FR-TKT-017): a response deadline
     * nobody answered, or a fix-by deadline the ticket has not been resolved
     * against. Shown to people as **Overdue**.
     *
     * The OR pair is wrapped so it cannot escape whatever scope the caller has
     * already applied. Does not itself restrict to live tickets — combine with
     * {@see constrainLive()}.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function constrainBreached(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= now();

        return $query->where(function (Builder $either) use ($now): void {
            $either->where(function (Builder $response) use ($now): void {
                $response->where('tickets.response_due_at', '<', $now)
                    ->whereNull('tickets.first_response_at');
            })->orWhere(function (Builder $resolution) use ($now): void {
                $resolution->where('tickets.resolution_due_at', '<', $now)
                    ->whereNull('tickets.resolved_at');
            });
        });
    }

    /**
     * Unresolved tickets whose fix-by deadline falls inside the next
     * `$leadHours` — inclusive at both ends, as the dashboard has always counted.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function constrainDueWithin(
        Builder $query,
        int $leadHours = self::AT_RISK_LEAD_HOURS,
        ?Carbon $now = null,
    ): Builder {
        $now ??= now();

        return $query->whereNull('tickets.resolved_at')
            ->whereNotNull('tickets.resolution_due_at')
            ->whereBetween('tickets.resolution_due_at', [$now, $now->copy()->addHours($leadHours)]);
    }

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
    public function posture(Ticket $ticket, int $atRiskLeadHours = self::AT_RISK_LEAD_HOURS): array
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
