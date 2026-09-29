<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers;

use App\Domains\KnowledgeBase\Http\Resources\TicketAiAnalysisResource;
use App\Domains\Tickets\Services\ReporterOutcome;
use App\Http\Controllers\Controller;
use App\Models\AiAnalysisLog;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

/**
 * The Teacher AI panel (WP-I, SRS FR-AI-021) — the full pre-screening for one
 * ticket, additive to (not a replacement for) `TicketDetailResource`'s own
 * administrator-only embedded snapshot.
 *
 * **Authorization is `viewFull`, never `view`.** `TicketPolicy::viewFull()` is
 * this project's existing name for exactly `TicketVisibility::canSeeFull()`
 * (see that policy method); using the weaker `view` ability (`canSee()`)
 * here would leak a full AI analysis — category, severity, technician-needed
 * signal, troubleshooting steps — to a Teacher who can see this ticket only as
 * a redacted community card, which is precisely the IDOR the WP-I mandate
 * calls out by name. Route-model binding resolves `$ticket` before this
 * runs, so a real uuid the caller cannot reach and an invented one both end
 * up refused here — see `viewFull()`'s own scope, which is identical to
 * `view()`'s for exactly this reason (SDD DD-40).
 */
class TicketAiAnalysisController extends Controller
{
    public function show(Ticket $ticket, ReporterOutcome $outcome): JsonResponse
    {
        $this->authorize('viewFull', $ticket);

        $log = AiAnalysisLog::query()
            ->where('ticket_id', $ticket->id)
            ->with(['recommendations' => fn ($query) => $query->orderBy('step_order')])
            ->latest('analyzed_at')
            ->first();

        if ($log === null) {
            return response()->json([
                'data' => null,
                'meta' => ['available' => false],
            ]);
        }

        return response()->json([
            'data' => new TicketAiAnalysisResource($log),
            'meta' => [
                'available' => true,
                // WP-J — what the reporter said after trying these steps, in the
                // ticket's current open period, so the panel survives a reload.
                'reporter_outcome' => $outcome->current($ticket),
            ],
        ]);
    }
}
