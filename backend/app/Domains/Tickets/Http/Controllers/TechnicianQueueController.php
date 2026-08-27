<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers;

use App\Domains\Tickets\Actions\RespondToAssignment;
use App\Domains\Tickets\Http\Requests\IndexTicketsRequest;
use App\Domains\Tickets\Http\Resources\TicketDetailResource;
use App\Domains\Tickets\Http\Resources\TicketListResource;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Enums\AssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * A technician's own work (SRS FR-ASN-004/006, Figure 4).
 *
 * This is the **only** ticket surface a technician can reach. There is no
 * browse, no feed and no directory here — `TicketVisibility` constrains every
 * query to tickets they hold an assignment for, and `TicketPolicy` refuses a
 * guessed uuid for the same reason, so the scoping is not something route
 * placement is doing.
 *
 * Two lists, because finished work reads differently from work in hand:
 * {@see index()} is the active queue, ordered by severity then deadline;
 * {@see history()} is what they have completed or handed on — readable
 * permanently for maintenance reference and audit, writable never (SDD DD-42).
 */
class TechnicianQueueController extends Controller
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly TicketLifecycle $lifecycle,
        private readonly TicketVisibility $visibility,
    ) {}

    /** Active assignments — most severe first, then soonest deadline. */
    public function index(IndexTicketsRequest $request, TicketDirectoryQuery $directory): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $tickets = $directory->queue($request->validated(), $user);
        $this->attachSlaPosture($tickets->getCollection());

        return TicketListResource::collection($tickets);
    }

    /** Completed or handed-on work — read-only. */
    public function history(IndexTicketsRequest $request, TicketDirectoryQuery $directory): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $tickets = $directory->history($request->validated(), $user);
        $this->attachSlaPosture($tickets->getCollection());

        return TicketListResource::collection($tickets);
    }

    /**
     * One assigned ticket, with everything needed to do the job: the fault, the
     * evidence, the equipment and where it is.
     */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        // Refuses any ticket this technician holds no assignment for — the same
        // check the queue query applies, so a uuid is not a way around it.
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();

        $ticket->load([
            'status', 'priority', 'category', 'tags',
            'reporter', 'assignedTechnician',
            'pcUnit.room.floor.building', 'room.floor.building',
            'attachments.uploadedBy',
        ]);

        $assignment = $this->assignmentFor($ticket, $user);

        return (new TicketDetailResource($ticket))
            ->additional(['meta' => [
                'transitions' => $this->lifecycle->availableTransitions($ticket, $user),
                'sla' => $this->sla->posture($ticket),
                'assignment' => $assignment !== null ? [
                    'status' => $assignment->status->value,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'accepted_at' => $assignment->accepted_at?->toIso8601String(),
                    'started_at' => $assignment->started_at?->toIso8601String(),
                    'completed_at' => $assignment->completed_at?->toIso8601String(),
                    'can' => [
                        'accept' => $user->can('accept', $assignment),
                        'decline' => $user->can('decline', $assignment),
                        'start' => $user->can('start', $assignment),
                        'hold' => $user->can('hold', $assignment),
                        'complete' => $user->can('complete', $assignment),
                    ],
                ] : null,
                // Read-only history keeps the ticket visible but every write
                // ability off; the client renders the page accordingly.
                'read_only' => ! $this->visibility->canWork($user, $ticket),
            ]])
            ->response();
    }

    /* ------------------------------------------------- assignment actions */

    public function accept(Request $request, Ticket $ticket, RespondToAssignment $action): JsonResponse
    {
        return $this->respond($request, $ticket, function (TechnicianAssignment $assignment, User $actor) use ($action, $request) {
            $this->authorize('accept', $assignment);

            return $action->accept($assignment, $actor, $request);
        }, 'Assignment accepted.');
    }

    public function decline(Request $request, Ticket $ticket, RespondToAssignment $action): JsonResponse
    {
        $validated = $request->validate([
            // A decline sends the ticket back to the queue, so the reason is the
            // only thing telling an administrator how to place it better.
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        return $this->respond($request, $ticket, function (TechnicianAssignment $assignment, User $actor) use ($action, $request, $validated) {
            $this->authorize('decline', $assignment);

            return $action->decline($assignment, (string) $validated['reason'], $actor, $request);
        }, 'Assignment declined and returned to the queue.');
    }

    public function start(Request $request, Ticket $ticket, RespondToAssignment $action): JsonResponse
    {
        return $this->respond($request, $ticket, function (TechnicianAssignment $assignment, User $actor) use ($action, $request) {
            $this->authorize('start', $assignment);

            return $action->start($assignment, $actor, $request);
        }, 'Work started.');
    }

    public function hold(Request $request, Ticket $ticket, RespondToAssignment $action): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond($request, $ticket, function (TechnicianAssignment $assignment, User $actor) use ($action, $request, $validated) {
            $this->authorize('hold', $assignment);

            return $action->hold($assignment, $validated['remarks'] ?? null, $actor, $request);
        }, 'Work put on hold.');
    }

    public function complete(Request $request, Ticket $ticket, RespondToAssignment $action): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond($request, $ticket, function (TechnicianAssignment $assignment, User $actor) use ($action, $request, $validated) {
            $this->authorize('complete', $assignment);

            return $action->complete($assignment, $validated['remarks'] ?? null, $actor, $request);
        }, 'Work completed — the reporter has been asked to confirm.');
    }

    /**
     * Shared shape for the five assignment actions: find *this* technician's
     * active assignment, run the closure, return the refreshed ticket.
     */
    private function respond(Request $request, Ticket $ticket, callable $handler, string $message): JsonResponse
    {
        $this->authorize('view', $ticket);

        /** @var User $actor */
        $actor = $request->user();

        $assignment = $this->assignmentFor($ticket, $actor, active: true);

        if ($assignment === null) {
            // Readable (they were assigned once) but no longer theirs to act on.
            abort(403, 'You have no active assignment on this ticket.');
        }

        $handler($assignment, $actor);

        $ticket->refresh()->load(['status', 'priority', 'category', 'reporter', 'assignedTechnician']);

        return (new TicketDetailResource($ticket))
            ->additional([
                'message' => $message,
                'meta' => ['transitions' => $this->lifecycle->availableTransitions($ticket, $actor)],
            ])
            ->response();
    }

    private function assignmentFor(Ticket $ticket, User $user, bool $active = false): ?TechnicianAssignment
    {
        return TechnicianAssignment::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('technician_id', $user->getKey())
            ->when($active, fn ($q) => $q->whereIn('status', AssignmentStatus::activeValues()))
            ->latest('id')
            ->first();
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    private function attachSlaPosture(Collection $tickets): void
    {
        $tickets->each(function (Ticket $ticket): void {
            $ticket->sla_posture = $this->sla->posture($ticket);
        });
    }
}
