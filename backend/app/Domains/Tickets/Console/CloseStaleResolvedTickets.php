<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Console;

use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Closes tickets left `Resolved` without a reporter's confirmation
 * (SRS FR-TKT-016).
 *
 * The primary path is that the reporter confirms a fix, or reopens it — that is
 * what makes `Resolved` a non-terminal state. But a requester who simply never
 * comes back must not leave a ticket open forever, so two independent releases
 * exist: an Administrator may close any resolved ticket at any time, and this
 * sweep closes what nobody has answered after `tickets.auto_close_days`.
 *
 * Two design points worth stating:
 *
 *  1. **It goes through `TicketLifecycle` like everything else.** A closure that
 *     wrote `current_status_id` directly would skip the status history, the
 *     activity feed and the audit trail — and an automated closure is precisely
 *     the one a reader is most likely to question later. Running it through the
 *     same service means the history says *"Closed automatically after 14 days
 *     without confirmation"* with a null actor, which is the honest record.
 *  2. **The reopen window runs from `closed_at`.** So an auto-closed ticket is
 *     still reopenable by its reporter for the configured window afterwards —
 *     the sweep tidies the queue, it does not slam a door.
 */
class CloseStaleResolvedTickets extends Command
{
    protected $signature = 'tickets:close-stale
                            {--dry-run : List what would close without changing anything}';

    protected $description = 'Close tickets left Resolved without reporter confirmation past the configured window';

    public function handle(TicketLifecycle $lifecycle): int
    {
        $days = $lifecycle->autoCloseDays();
        $cutoff = now()->subDays($days);

        $closed = TicketStatus::query()->where('slug', 'closed')->first();

        if ($closed === null) {
            $this->error('No "closed" status is configured — nothing to do.');

            return self::FAILURE;
        }

        $stale = Ticket::query()
            ->whereHas('status', fn (Builder $q): Builder => $q->where('slug', 'resolved'))
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', $cutoff)
            ->with('status')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No tickets have been awaiting confirmation for more than {$days} days.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Ticket', 'Title', 'Resolved'],
                $stale->map(fn (Ticket $t): array => [
                    $t->ticket_number,
                    Str::limit($t->title, 50),
                    $t->resolved_at?->diffForHumans(),
                ])->all(),
            );
            $this->info("{$stale->count()} ticket(s) would be closed.");

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($stale as $ticket) {
            try {
                // Null actor: the transition map grants `ACTOR_SYSTEM` exactly
                // one move — resolved → closed — and nothing else.
                $lifecycle->transition(
                    $ticket,
                    $closed,
                    null,
                    "Closed automatically after {$days} days without confirmation from the reporter.",
                    null,
                    ActivityAction::TicketAutoClosed,
                );
                $count++;
            } catch (\Throwable $exception) {
                // One bad row must not abandon the rest of the sweep.
                $this->warn("Could not close {$ticket->ticket_number}: {$exception->getMessage()}");
            }
        }

        $this->info("Closed {$count} ticket(s) awaiting confirmation for more than {$days} days.");

        return self::SUCCESS;
    }
}
