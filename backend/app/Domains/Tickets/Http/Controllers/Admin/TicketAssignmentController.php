<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers\Admin;

use App\Domains\Tickets\Actions\AssignTicket;
use App\Domains\Tickets\Http\Requests\AssignTicketRequest;
use App\Domains\Tickets\Http\Resources\TicketDetailResource;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Assigning and reassigning tickets (SRS FR-ASN-001/002/003).
 *
 * Authority sits with the Administrator. A Technician does **not** hold
 * `tickets.assign` in the seeded baseline — but the permission remains
 * grantable per-user, which is FR-ASN-001's own *"(and permitted Technicians)"*
 * exception for a lead technician who distributes workload. That is
 * deputization, not self-service: the deputy still passes through
 * `TicketPolicy::assign()` and every assignment is audited with its actor.
 *
 * Both endpoints go through the same Action, because reassignment *is*
 * assignment with a predecessor to close — splitting them would be two write
 * paths to the same invariant.
 */
class TicketAssignmentController extends Controller
{
    /** Assign an unowned ticket, or hand it to someone else. */
    public function store(AssignTicketRequest $request, Ticket $ticket, AssignTicket $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $technician = User::query()
            ->where('uuid', (string) $request->validated('technician'))
            ->firstOrFail();

        $wasAssigned = $ticket->assigned_technician_id !== null;

        $action->handle($ticket, $technician, $actor, $request, $request->validated('remarks'));

        $ticket->refresh()->load(['status', 'priority', 'category', 'reporter', 'assignedTechnician']);

        return (new TicketDetailResource($ticket))
            ->additional([
                'message' => $wasAssigned
                    ? "Reassigned to {$technician->fullName()}."
                    : "Assigned to {$technician->fullName()}.",
            ])
            ->response();
    }
}
