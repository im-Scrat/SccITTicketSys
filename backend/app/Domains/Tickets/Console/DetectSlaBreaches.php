<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Console;

use App\Domains\Tickets\Events\TicketSlaThresholdCrossed;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Detects tickets that have crossed an SLA threshold (SRS FR-TKT-017,
 * FR-NOT-003 T4).
 *
 * ── Why this command has to exist ──────────────────────────────────────────
 *
 * `SlaCalculator` computes breach **on read, never stored** — deliberately, so
 * the posture on a screen is always current rather than as stale as the last
 * sweep. That design has one gap, which the calculator's own docblock names:
 * *"FR-TKT-017's notification is the only part that needs a scheduled job."*
 * Nobody is looking at the screen at 3am, and a deadline that passes unobserved
 * still passed. This is that job.
 *
 * It stores nothing and changes nothing. It re-derives posture from the same
 * `SlaCalculator` the ticket directory and the technician queue use — so the
 * sweep and the dashboards can never disagree about what has breached — and
 * raises an event per crossing.
 *
 * ── Four crossings, each announced once ────────────────────────────────────
 *
 * A ticket has two clocks, response and resolution, and each is either
 * *approaching* or *past* its deadline. The command re-detects the same states
 * every hour it runs; what stops that becoming an hourly nag is the
 * notification's idempotency key, which is `{ticket}:{stage}` with no date in
 * it. Each of the four crossings is therefore announced exactly once in a
 * ticket's life, by the database rather than by any bookkeeping here.
 *
 * That is also why this runs **hourly** while the other two scheduled commands
 * run daily: SLA windows for a high-priority ticket are measured in hours, so a
 * daily sweep would routinely report a breach most of a day after it happened.
 * The cost of the finer cadence is bounded by the same key — re-running it finds
 * the same crossings and writes nothing new.
 *
 * ── Recipients are not decided here ────────────────────────────────────────
 *
 * The command detects; `NotifyOnTicketSlaThreshold` decides who hears about it
 * (administrators and the assigned technician, by the Client's decision of
 * 2026-09-05). Keeping detection free of recipients is what lets the same event
 * later feed an escalation rule, if OI-06 is ever settled that way, without
 * touching this file.
 */
class DetectSlaBreaches extends Command
{
    protected $signature = 'tickets:detect-sla
                            {--lead= : Hours of warning before a deadline counts as at risk}';

    protected $description = 'Detect tickets that have breached, or are approaching, their SLA deadlines';

    /**
     * The default warning window, in hours.
     *
     * Matches `SlaCalculator::posture()`'s own default, which every ticket
     * screen already relies on. A different value here would mean a technician
     * notified about a ticket their queue does not yet show as at risk.
     */
    private const DEFAULT_LEAD_HOURS = 4;

    public function handle(SlaCalculator $sla): int
    {
        $lead = $this->option('lead') !== null
            ? max(1, (int) $this->option('lead'))
            : self::DEFAULT_LEAD_HOURS;

        $counts = ['breached' => 0, 'at_risk' => 0];

        /*
         * Chunked by id rather than loaded whole. The live set is small in this
         * deployment, but a sweep that loads every open ticket into memory is a
         * sweep that stops working at exactly the moment the desk is busiest.
         */
        $this->live()->chunkById(200, function ($tickets) use ($sla, $lead, &$counts): void {
            foreach ($tickets as $ticket) {
                foreach ($this->stagesFor($ticket, $sla, $lead) as $stage) {
                    TicketSlaThresholdCrossed::dispatch($ticket, $stage);

                    $counts[str_contains($stage, 'breached') ? 'breached' : 'at_risk']++;
                }
            }
        });

        $this->info(sprintf(
            'SLA sweep: %d breached, %d at risk within %d hour(s).',
            $counts['breached'],
            $counts['at_risk'],
            $lead,
        ));

        return self::SUCCESS;
    }

    /**
     * Which thresholds this ticket has crossed, right now.
     *
     * A breach supersedes the warning for the *same* clock — a ticket that is
     * already past its resolution deadline is not also "approaching" it — but
     * the two clocks are independent, so a ticket can legitimately have breached
     * its response deadline while merely approaching its resolution one.
     *
     * @return list<string>
     */
    private function stagesFor(Ticket $ticket, SlaCalculator $sla, int $lead): array
    {
        $posture = $sla->posture($ticket, $lead);
        $stages = [];

        if ($posture['response_breached']) {
            $stages[] = TicketSlaThresholdCrossed::STAGE_RESPONSE_BREACHED;
        } elseif ($this->responseAtRisk($ticket, $lead)) {
            $stages[] = TicketSlaThresholdCrossed::STAGE_RESPONSE_AT_RISK;
        }

        if ($posture['resolution_breached']) {
            $stages[] = TicketSlaThresholdCrossed::STAGE_RESOLUTION_BREACHED;
        } elseif ($posture['at_risk']) {
            $stages[] = TicketSlaThresholdCrossed::STAGE_RESOLUTION_AT_RISK;
        }

        return $stages;
    }

    /**
     * The one posture `SlaCalculator` does not already expose.
     *
     * `posture()['at_risk']` is about the *resolution* clock, because that is
     * what the ticket screens display. The response clock needs the same
     * question asked of it here, computed the same way — deadline set, no
     * response yet, and the deadline inside the lead window.
     */
    private function responseAtRisk(Ticket $ticket, int $lead): bool
    {
        if ($ticket->response_due_at === null || $ticket->first_response_at !== null) {
            return false;
        }

        $minutes = (int) now()->diffInMinutes($ticket->response_due_at, false);

        return $minutes >= 0 && $minutes <= $lead * 60;
    }

    /**
     * Tickets whose clock is still running.
     *
     * The same definition `SlaCalculator::posture()` uses for a "live" ticket —
     * not terminal, not resolved — applied as a query so the sweep never loads
     * the historical rows it would then discard.
     *
     * @return Builder<Ticket>
     */
    private function live(): Builder
    {
        return Ticket::query()
            ->with(['status', 'priority', 'assignedTechnician'])
            ->whereNull('resolved_at')
            ->whereHas('status', fn (Builder $query) => $query->where('is_terminal', false))
            ->where(function (Builder $query): void {
                $query->whereNotNull('response_due_at')->orWhereNotNull('resolution_due_at');
            });
    }
}
