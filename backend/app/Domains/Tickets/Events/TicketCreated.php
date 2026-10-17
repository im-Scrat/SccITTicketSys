<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a ticket is reported.
 *
 * **Raised in Phase 2.6 with no listener**, as the seam later phases attach to
 * rather than editing `CreateTicket` — the whole point of the modular
 * monolith's event boundary (SDD §14.2). It has since gained two, exactly that
 * way: KnowledgeBase's `AnalyzeTicketOnCreated` queues the asynchronous AI
 * pre-screening (SRS FR-AI-021, UCS-02 step 5; WP-I), and Analytics'
 * `SignalAdminDashboard` pushes the administrator dashboard's realtime signal
 * (Phase 2.9).
 *
 * An event with no listener costs one no-op dispatch. An event added later costs
 * a change to the write path everyone depends on.
 */
class TicketCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Ticket $ticket) {}
}
