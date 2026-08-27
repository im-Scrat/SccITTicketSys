<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers;

use App\Domains\Tickets\Actions\ChangeTicketStatus;
use App\Domains\Tickets\Actions\CreateTicket;
use App\Domains\Tickets\Actions\UpdateTicket;
use App\Domains\Tickets\Http\Requests\ChangeTicketStatusRequest;
use App\Domains\Tickets\Http\Requests\IndexTicketsRequest;
use App\Domains\Tickets\Http\Requests\StoreTicketRequest;
use App\Domains\Tickets\Http\Requests\UpdateTicketRequest;
use App\Domains\Tickets\Http\Resources\TicketDetailResource;
use App\Domains\Tickets\Http\Resources\TicketFeedResource;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketVote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * A requester's own tickets: reporting, reading, editing and the three
 * reporter-owned lifecycle moves (SRS FR-TKT-001..006/016).
 *
 * The interesting method is {@see show()}. **One route serves two projections**:
 * the reporter (or an administrator, or an assigned technician) receives the
 * full record, while another requester receives the restricted community card.
 * The choice is made by `TicketVisibility` — the same service that scopes the
 * lists — so a uuid can never yield more than a list would have shown.
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly TicketVisibility $visibility,
        private readonly TicketLifecycle $lifecycle,
    ) {}

    /** The requester's own tickets, in full. */
    public function mine(IndexTicketsRequest $request, TicketDirectoryQuery $directory): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $tickets = $directory->own($request->validated(), $user);
        $this->markVoted($tickets->getCollection(), $user);

        return TicketFeedResource::collection($tickets);
    }

    /**
     * One ticket, in whichever projection this caller is entitled to.
     *
     * The policy has already refused anyone with no access at all (403), so by
     * this point the only question is *which* resource.
     */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();

        if (! $this->visibility->canSeeFull($user, $ticket)) {
            // Another requester's ticket: the community card, never the record.
            $this->markVoted(collect([$ticket]), $user);

            return (new TicketFeedResource($ticket))->response();
        }

        $ticket->load([
            'status', 'priority', 'category', 'tags',
            'reporter', 'assignedTechnician', 'duplicateOf',
            'pcUnit.room.floor.building', 'room.floor.building',
            'attachments.uploadedBy',
        ]);
        $this->markVoted(collect([$ticket]), $user);

        return (new TicketDetailResource($ticket))
            ->additional(['meta' => [
                'transitions' => $this->lifecycle->availableTransitions($ticket, $user),
                'can' => $this->abilities($user, $ticket),
                'reopen_window_days' => $this->lifecycle->reopenWindowDays(),
            ]])
            ->response();
    }

    public function store(StoreTicketRequest $request, CreateTicket $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $ticket = $action->handle($request->validated(), $actor, $request);

        return (new TicketDetailResource($ticket))
            ->additional(['message' => "Reported as {$ticket->ticket_number}."])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($ticket, $request->validated(), $actor, $request);

        return (new TicketDetailResource($ticket->load(['status', 'priority', 'category', 'reporter'])))
            ->additional(['message' => 'Ticket updated.'])
            ->response();
    }

    /**
     * The reporter's three lifecycle moves — confirm, reopen, cancel — plus the
     * generic transition for staff. They share one endpoint because they are one
     * operation with different authority, and `TicketLifecycle` already knows
     * which actor may make which move.
     */
    public function changeStatus(
        ChangeTicketStatusRequest $request,
        Ticket $ticket,
        ChangeTicketStatus $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($ticket, $request->target(), $actor, $request, $request->validated('remarks'));

        $ticket->load(['status', 'priority', 'category', 'reporter', 'assignedTechnician']);

        return (new TicketDetailResource($ticket))
            ->additional([
                'message' => "Status updated to {$ticket->status?->name}.",
                'meta' => ['transitions' => $this->lifecycle->availableTransitions($ticket, $actor)],
            ])
            ->response();
    }

    /**
     * Flag which tickets the caller has already voted on.
     *
     * Done as one `whereIn` for the whole page rather than a per-row `exists`,
     * so a 20-row feed costs one query instead of twenty.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    private function markVoted(Collection $tickets, User $user): void
    {
        if ($tickets->isEmpty()) {
            return;
        }

        $voted = TicketVote::query()
            ->where('user_id', $user->getKey())
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->pluck('ticket_id')
            ->all();

        $tickets->each(function (Ticket $ticket) use ($voted): void {
            $ticket->has_voted = in_array($ticket->getKey(), $voted, true);
        });
    }

    /**
     * What this caller may do with this ticket, resolved server-side so the
     * client renders affordances from the same rules the API enforces rather
     * than re-deriving them.
     *
     * @return array<string, bool>
     */
    private function abilities(User $user, Ticket $ticket): array
    {
        return [
            'update' => $user->can('update', $ticket),
            'comment' => $user->can('comment', $ticket),
            'comment_internal' => $user->can('commentInternal', $ticket),
            'vote' => $user->can('vote', $ticket),
            'attach' => $user->can('manageAttachments', $ticket),
            'confirm_resolution' => $user->can('confirmResolution', $ticket),
            'reopen' => $user->can('reopen', $ticket) && $this->lifecycle->withinReopenWindow($ticket),
            'cancel' => $user->can('cancelOwn', $ticket),
            'assign' => $user->can('assign', $ticket),
            'change_priority' => $user->can('changePriority', $ticket),
        ];
    }
}
