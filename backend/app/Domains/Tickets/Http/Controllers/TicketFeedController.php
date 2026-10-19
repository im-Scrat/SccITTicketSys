<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers;

use App\Domains\Tickets\Http\Requests\IndexTicketsRequest;
use App\Domains\Tickets\Http\Resources\TicketFeedResource;
use App\Domains\Tickets\Services\DuplicateFinder;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Http\Controllers\Controller;
use App\Models\PcUnit;
use App\Models\Ticket;
use App\Models\TicketVote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The requester community feed (SRS UCS-02 step 1, FR-TKT-009/011/014).
 *
 * Its purpose is duplicate avoidance: before writing a new report, a teacher
 * should be able to see that the printer in Lab 3 is already reported, and add
 * their upvote instead of a second ticket. That is the whole reason a requester
 * sees anyone else's ticket at all.
 *
 * Two properties keep it safe:
 *
 *  - **Every row is a {@see TicketFeedResource}**, whose shape cannot express
 *    internal comments, attachments, technicians, SLA or AI fields. The
 *    redaction is structural, not a filter someone could forget to apply.
 *  - **Technicians receive 403, not an empty list.** Their surface is the
 *    assigned queue; returning an empty feed would misrepresent it as "no
 *    tickets exist" rather than "this is not yours to browse".
 *
 * Cursor-paginated: rows arriving mid-scroll must not shift the ones already
 * read, which offset paging cannot promise.
 */
class TicketFeedController extends Controller
{
    /** The feed itself. */
    public function index(IndexTicketsRequest $request, TicketDirectoryQuery $directory): JsonResponse
    {
        $this->authorize('viewFeed', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        $tickets = $directory->feed($request->validated(), $user);
        $this->markVoted(collect($tickets->items()), $user);

        return TicketFeedResource::collection($tickets)->response();
    }

    /**
     * One feed card by uuid.
     *
     * Deliberately a separate route from `GET /tickets/{uuid}`: this one *always*
     * returns the community projection, so a client that wants the card cannot
     * accidentally receive the full record, and vice versa.
     */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('viewFeed', Ticket::class);
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();
        $this->markVoted(collect([$ticket]), $user);

        return (new TicketFeedResource($ticket))->response();
    }

    /**
     * Candidate duplicates for a fault the requester is describing
     * (FR-TKT-011).
     *
     * **Text similarity, not AI** — ranked `tsvector` plus a strong boost for
     * the same machine. The client labels it as "similar reports" rather than
     * implying analysis; genuine AI duplicate suggestion is FR-AI-009, a later
     * phase.
     */
    public function duplicates(
        IndexTicketsRequest $request,
        DuplicateFinder $finder,
    ): AnonymousResourceCollection {
        $this->authorize('create', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        $pcUnitUuid = $request->validated('pc_unit');
        $pcUnit = is_string($pcUnitUuid) && $pcUnitUuid !== ''
            ? PcUnit::query()->where('uuid', $pcUnitUuid)->first()
            : null;

        $candidates = $finder->search($request->validated('search'), $pcUnit);
        $this->markVoted($candidates, $user);

        return TicketFeedResource::collection($candidates);
    }

    /**
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
}
