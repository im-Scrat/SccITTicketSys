<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Console;

use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\AssignmentStatus;
use App\Models\TechnicianAssignment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair for assignments stranded on tickets that are already closed or
 * cancelled (SDD DD-83).
 *
 * `TicketLifecycle::transition()` now ends the active assignment as the ticket
 * reaches a terminal status. Tickets that got there *before* that fix — resolved
 * through the generic "Move this ticket" control, then confirmed by the reporter
 * — still carry a `pending`/`accepted`/`in_progress`/`on_hold` assignment, which
 * keeps them in the technician's active queue and keeps the write access
 * `canWork` grants. This applies the same rule to them, through the same method
 * (`TicketLifecycle::endActiveAssignment()`), so the repair and the fix cannot
 * drift apart.
 *
 * Safe to re-run: once an assignment has ended it is no longer active, so a second
 * pass finds nothing. Non-terminal tickets are never selected — a `resolved`
 * ticket legitimately keeps its assignment until it closes.
 *
 * It reports counts only. `--dry-run` computes the outcome of each assignment
 * without writing anything.
 */
class EndStaleAssignments extends Command
{
    protected $signature = 'tickets:end-stale-assignments
                            {--dry-run : Report what would change without changing anything}';

    protected $description = 'End active technician assignments that are stranded on closed or cancelled tickets';

    public function handle(TicketLifecycle $lifecycle): int
    {
        $stale = TechnicianAssignment::query()
            ->whereIn('status', AssignmentStatus::activeValues())
            ->whereHas('ticket.status', fn (Builder $q): Builder => $q->where('is_terminal', true))
            ->with('ticket.status')
            ->orderBy('id')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No active assignments are attached to closed or cancelled tickets.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $outcomes = [
            AssignmentStatus::Completed->value => 0,
            AssignmentStatus::Cancelled->value => 0,
        ];
        $failed = 0;

        foreach ($stale as $assignment) {
            $ticket = $assignment->ticket;
            $terminal = $ticket?->status;

            if ($ticket === null || $terminal === null) {
                continue;
            }

            try {
                if ($dryRun) {
                    [$status] = $lifecycle->assignmentOutcome($ticket, $terminal, $assignment);
                } else {
                    // Own transaction per row: one bad row must not abandon the
                    // rest, and the assignment write, timeline row and audit row
                    // for a ticket stand or fall together.
                    $status = DB::transaction(
                        fn (): ?AssignmentStatus => $lifecycle->endActiveAssignment($ticket, $terminal),
                    );
                }

                if ($status !== null) {
                    $outcomes[$status->value]++;
                }
            } catch (\Throwable $exception) {
                $failed++;
                $this->warn("Could not end assignment #{$assignment->getKey()}: {$exception->getMessage()}");
            }
        }

        $verb = $dryRun ? 'would be' : 'were';
        $this->info(sprintf(
            '%d assignment(s) %s ended: %d completed, %d cancelled.',
            $outcomes['completed'] + $outcomes['cancelled'],
            $verb,
            $outcomes['completed'],
            $outcomes['cancelled'],
        ));

        if ($dryRun) {
            $this->line('Dry run — nothing was written.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
