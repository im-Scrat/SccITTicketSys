<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers;

use App\Domains\Tickets\Services\TicketTimeline;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A ticket's history tab (Phase 2.9, FR-TKT-012).
 *
 * `viewFull`, never `view`: the community card is all another requester may
 * see of a ticket, and a history is part of the full record. A ticket absent
 * from the caller's lists is refused here by uuid exactly the same way
 * (SDD DD-40). What each permitted viewer then reads is {@see TicketTimeline}'s
 * decision.
 */
class TicketTimelineController extends Controller
{
    public function index(Request $request, Ticket $ticket, TicketTimeline $timeline): JsonResponse
    {
        $this->authorize('viewFull', $ticket);

        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json(['data' => $timeline->for($ticket, $viewer)]);
    }
}
