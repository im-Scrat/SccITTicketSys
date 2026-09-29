<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\TicketUpdateType;
use App\Models\AiAnalysisLog;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WP-J NOT FIXED — the reporter tried the AI's recommendations and the fault
 * is still there.
 *
 * **Deliberately not a status transition.** The mandate is explicit: no new
 * status, the ticket stays `open` with `assigned_technician_id IS NULL`, so
 * the Administrator's existing triage surface — `TicketDirectoryQuery`'s
 * `technician=unassigned` filter and the dashboard's unassigned-open count —
 * picks it up with no new queue, flag or column. That is why this does not go
 * through `TicketLifecycle::transition()`: there is no transition to make,
 * and routing a same-status "move" through it would be a silent no-op.
 *
 * What it *does* record is the outcome, so triage is not blind to it: an
 * `ai_analysis` timeline entry in the reporter's own words and a dedicated
 * audit verb. Nulling the technician is a guarantee rather than usually a
 * change — a declined assignment already clears it (`RespondToAssignment`) —
 * which is exactly why the status is re-read under a row lock first: between
 * the policy check and this write an Administrator may have assigned the
 * ticket, and clearing that assignment would quietly undo their triage.
 */
class ReportTicketNotFixed
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException when the ticket is no longer open
     */
    public function handle(Ticket $ticket, User $actor, Request $request, ?string $remarks = null): Ticket
    {
        $hadTechnician = DB::transaction(function () use ($ticket, $actor, $remarks): bool {
            /** @var Ticket $locked */
            $locked = Ticket::query()->with('status')->lockForUpdate()->findOrFail($ticket->getKey());

            if ($locked->status?->slug !== 'open') {
                throw ValidationException::withMessages([
                    'status' => 'This ticket has already moved on — a technician or administrator is handling it.',
                ]);
            }

            $hadTechnician = $locked->assigned_technician_id !== null;

            $locked->forceFill([
                'assigned_technician_id' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            TicketUpdate::query()->create([
                'ticket_id' => $locked->getKey(),
                'user_id' => $actor->getKey(),
                'update_type' => TicketUpdateType::AiAnalysis->value,
                'body' => $remarks,
                'metadata' => [
                    'outcome' => 'not_fixed',
                    'ai_analysis' => AiAnalysisLog::query()
                        ->where('ticket_id', $locked->getKey())
                        ->latest('analyzed_at')
                        ->value('uuid'),
                ],
                'created_at' => now(),
            ]);

            return $hadTechnician;
        });

        $this->audit->activity(
            ActivityAction::TicketNotFixedByReporter,
            actor: $actor,
            subject: $ticket,
            properties: [
                'outcome' => 'not_fixed',
                'remarks' => $remarks,
                // True only in the rare case the guarantee actually changed something.
                'cleared_technician' => $hadTechnician,
            ],
            request: $request,
            module: 'tickets',
            description: "Ticket {$ticket->ticket_number}: not fixed by reporter after AI troubleshooting",
        );

        return $ticket->refresh();
    }
}
