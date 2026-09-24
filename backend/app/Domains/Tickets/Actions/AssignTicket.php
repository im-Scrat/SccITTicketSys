<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tickets\Events\TicketAssigned;
use App\Domains\Tickets\Exceptions\AssignmentConflictException;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Enums\TicketUpdateType;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Assign — or reassign — a ticket to a technician (SRS FR-ASN-001/002/003).
 *
 * One transaction does five things, because a half-applied assignment is worse
 * than none: close any prior active assignment as `reassigned`, insert the new
 * one, point `tickets.assigned_technician_id` at the technician, move the status
 * to `Assigned`, and write the activity entry.
 *
 * **The one-active-assignment rule is the database's, not ours.** The partial
 * unique index `technician_assignments_one_active_per_ticket` is what actually
 * enforces FR-ASN-002; two administrators assigning simultaneously will have one
 * transaction rejected by Postgres. We catch that and answer **409 Conflict**
 * rather than letting it surface as a 500 — the caller's request was reasonable,
 * it just lost a race, and the correct response is "reload and look again".
 */
class AssignTicket
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TicketLifecycle $lifecycle,
    ) {}

    /**
     * @throws AssignmentConflictException when another assignment won the race
     */
    public function handle(
        Ticket $ticket,
        User $technician,
        User $actor,
        Request $request,
        ?string $remarks = null,
    ): TechnicianAssignment {
        $ticket->loadMissing(['status', 'assignedTechnician']);
        $previous = $this->activeAssignment($ticket);
        $isReassignment = $previous !== null;

        if ($previous !== null && $previous->technician_id === $technician->getKey()) {
            // Already theirs and still active — reassigning to the same person
            // would close and reopen an identical row for no reason.
            return $previous;
        }

        try {
            $assignment = DB::transaction(function () use ($ticket, $technician, $actor, $remarks, $previous): TechnicianAssignment {
                if ($previous !== null) {
                    $previous->forceFill([
                        'status' => AssignmentStatus::Reassigned->value,
                        'remarks' => $previous->remarks,
                    ])->save();
                }

                $assignment = TechnicianAssignment::query()->create([
                    'ticket_id' => $ticket->getKey(),
                    'technician_id' => $technician->getKey(),
                    'assigned_by' => $actor->getKey(),
                    'status' => AssignmentStatus::Pending->value,
                    'assigned_at' => now(),
                    'remarks' => $remarks,
                ]);

                $ticket->forceFill([
                    'assigned_technician_id' => $technician->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                TicketUpdate::query()->create([
                    'ticket_id' => $ticket->getKey(),
                    'user_id' => $actor->getKey(),
                    'update_type' => TicketUpdateType::Assignment->value,
                    'body' => $remarks,
                    'metadata' => [
                        'technician' => $technician->fullName(),
                        'technician_id' => $technician->uuid,
                        'reassigned' => $previous !== null,
                    ],
                    'created_at' => now(),
                ]);

                return $assignment;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new AssignmentConflictException;
            }

            throw $exception;
        }

        // The status move is its own audited transition, so it runs after the
        // assignment lands rather than inside it — the lifecycle service owns
        // that write path and must not be bypassed.
        $this->moveToAssigned($ticket, $actor, $request);

        $this->audit->activity(
            $isReassignment ? ActivityAction::TicketReassigned : ActivityAction::TicketAssigned,
            actor: $actor,
            subject: $ticket,
            properties: [
                'ticket_number' => $ticket->ticket_number,
                'to' => $technician->fullName(),
                'to_id' => $technician->uuid,
                'from' => $previous?->technician?->fullName(),
                'remarks' => $remarks,
            ],
            request: $request,
            module: 'tickets',
            description: sprintf(
                'Ticket %s %s %s',
                $ticket->ticket_number,
                $isReassignment ? 'reassigned to' : 'assigned to',
                $technician->fullName(),
            ),
        );

        /*
         * WP-2.7a — the notification seam (FR-ASN-005, FR-NOT-003 T1).
         *
         * Raised after the transaction and after the audit entry, so a
         * notification is only ever about an assignment that actually landed.
         * Both technicians travel on the event because a reassignment is two
         * facts to two people, and the closed `$previous` row would have to be
         * re-read to recover the second afterwards.
         *
         * Nothing here can affect the return value: the dispatcher swallows
         * delivery failures, so an assignment that succeeded stays succeeded
         * whatever the queue is doing.
         */
        TicketAssigned::dispatch(
            $ticket,
            $assignment,
            $technician,
            $previous?->technician,
            $actor,
        );

        return $assignment;
    }

    /**
     * Move the ticket to `Assigned`, unless it is already somewhere further
     * along. Reassigning a ticket that is `in-progress` should not drag it
     * backwards — the work has genuinely started, whoever is now doing it.
     */
    private function moveToAssigned(Ticket $ticket, User $actor, Request $request): void
    {
        $ticket->refresh()->loadMissing('status');

        if (in_array($ticket->status?->slug, ['assigned', 'in-progress', 'on-hold'], true)) {
            return;
        }

        $assigned = TicketStatus::query()->where('slug', 'assigned')->first();

        if ($assigned !== null) {
            $this->lifecycle->transition($ticket, $assigned, $actor, null, $request, ActivityAction::TicketAssigned);
        }
    }

    private function activeAssignment(Ticket $ticket): ?TechnicianAssignment
    {
        return TechnicianAssignment::query()
            ->with('technician')
            ->where('ticket_id', $ticket->getKey())
            ->whereIn('status', AssignmentStatus::activeValues())
            ->first();
    }

    /** Postgres reports a unique-constraint violation as SQLSTATE 23505. */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505';
    }
}
