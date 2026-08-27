<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a ticket is reported.
 *
 * **Deliberately has no listener in Phase 2.6.** It is the seam the
 * asynchronous AI analysis job attaches to in the AI phase (SRS FR-AI-021,
 * UCS-02 step 5) and the seam the notification domain attaches to for
 * FR-NOT-003. Raising it now means those phases register a listener rather than
 * editing `CreateTicket` — which is the whole point of the modular monolith's
 * event boundary (SDD §14.2).
 *
 * An event with no listener costs one no-op dispatch. An event added later costs
 * a change to the write path everyone depends on.
 */
class TicketCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Ticket $ticket) {}
}
