<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Enums\TicketUpdateType;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The assigned technician's own workflow: accept, decline, start, hold, complete
 * (SRS FR-ASN-003/004).
 *
 * Each action moves the **assignment** and, where the two should agree, the
 * **ticket** with it — a technician who starts work should not have to
 * separately remember to set the ticket to In Progress. The ticket move always
 * goes through `TicketLifecycle`, so the pairing cannot bypass the transition
 * map or the audit trail.
 *
 * **Declining returns the ticket to the queue**, not to limbo: the assignment
 * closes as `declined` with its reason, `assigned_technician_id` is cleared, and
 * the ticket goes back to `Open` for an administrator to place elsewhere. That
 * also revokes the declining technician's access, because a decline is a refusal
 * of the work and leaves no history worth referencing (SDD DD-42).
 */
class RespondToAssignment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TicketLifecycle $lifecycle,
    ) {}

    public function accept(TechnicianAssignment $assignment, User $actor, Request $request): TechnicianAssignment
    {
        return $this->apply(
            $assignment,
            AssignmentStatus::Accepted,
            ['accepted_at' => now()],
            null,
            $actor,
            $request,
            ActivityAction::TicketAssignmentAccepted,
            'accepted the assignment',
        );
    }

    public function decline(
        TechnicianAssignment $assignment,
        string $reason,
        User $actor,
        Request $request,
    ): TechnicianAssignment {
        $assignment = $this->apply(
            $assignment,
            AssignmentStatus::Declined,
            ['declined_at' => now(), 'decline_reason' => $reason],
            'open',
            $actor,
            $request,
            ActivityAction::TicketAssignmentDeclined,
            'declined the assignment',
            $reason,
        );

        // Back to the unassigned queue so an administrator can place it, rather
        // than sitting assigned to someone who has said no.
        $assignment->ticket?->forceFill(['assigned_technician_id' => null])->save();

        return $assignment;
    }

    public function start(TechnicianAssignment $assignment, User $actor, Request $request): TechnicianAssignment
    {
        return $this->apply(
            $assignment,
            AssignmentStatus::InProgress,
            ['started_at' => $assignment->started_at ?? now(), 'accepted_at' => $assignment->accepted_at ?? now()],
            'in-progress',
            $actor,
            $request,
            ActivityAction::TicketWorkStarted,
            'started work',
        );
    }

    public function hold(
        TechnicianAssignment $assignment,
        ?string $remarks,
        User $actor,
        Request $request,
    ): TechnicianAssignment {
        return $this->apply(
            $assignment,
            AssignmentStatus::OnHold,
            ['remarks' => $remarks],
            'on-hold',
            $actor,
            $request,
            ActivityAction::TicketWorkHeld,
            'put the work on hold',
            $remarks,
        );
    }

    public function complete(
        TechnicianAssignment $assignment,
        ?string $remarks,
        User $actor,
        Request $request,
    ): TechnicianAssignment {
        return $this->apply(
            $assignment,
            AssignmentStatus::Completed,
            ['completed_at' => now(), 'remarks' => $remarks],
            'resolved',
            $actor,
            $request,
            ActivityAction::TicketWorkCompleted,
            'completed the work',
            $remarks,
        );
    }

    /**
     * Move the assignment, optionally move the ticket with it, and audit both.
     *
     * @param  array<string, mixed>  $stamps
     */
    private function apply(
        TechnicianAssignment $assignment,
        AssignmentStatus $status,
        array $stamps,
        ?string $ticketStatusSlug,
        User $actor,
        Request $request,
        ActivityAction $action,
        string $phrase,
        ?string $body = null,
    ): TechnicianAssignment {
        $assignment->loadMissing('ticket');
        $ticket = $assignment->ticket;

        DB::transaction(function () use (
            $assignment, $status, $stamps, $ticketStatusSlug, $actor, $request, $ticket, $action, $body, $phrase
        ): void {
            /*
             * The ticket moves *first*, deliberately.
             *
             * `TicketLifecycle` decides an actor's authority from their **active**
             * assignment, so flipping this assignment to `declined` or `completed`
             * before the transition would strip the very authority the transition
             * needs and the move would be refused. Doing it in this order also
             * means an illegal transition aborts before the assignment is touched,
             * rather than leaving the two disagreeing.
             */
            if ($ticket !== null && $ticketStatusSlug !== null) {
                $target = TicketStatus::query()->where('slug', $ticketStatusSlug)->first();

                if ($target !== null) {
                    $this->lifecycle->transition($ticket, $target, $actor, $body, $request, $action);
                }
            }

            $assignment->forceFill(['status' => $status->value, ...$stamps])->save();

            if ($ticket !== null) {
                TicketUpdate::query()->create([
                    'ticket_id' => $ticket->getKey(),
                    'user_id' => $actor->getKey(),
                    'update_type' => TicketUpdateType::Assignment->value,
                    'body' => $body,
                    'metadata' => [
                        'assignment_status' => $status->value,
                        'technician' => $actor->fullName(),
                        'phrase' => $phrase,
                    ],
                    'created_at' => now(),
                ]);
            }
        });

        $this->audit->activity(
            $action,
            actor: $actor,
            subject: $ticket,
            properties: [
                'ticket_number' => $ticket?->ticket_number,
                'assignment_status' => $status->value,
                'reason' => $body,
            ],
            request: $request,
            module: 'tickets',
            description: sprintf(
                '%s %s on %s',
                $actor->fullName(),
                $phrase,
                $ticket instanceof Ticket ? $ticket->ticket_number : 'a ticket',
            ),
        );

        return $assignment->refresh();
    }
}
