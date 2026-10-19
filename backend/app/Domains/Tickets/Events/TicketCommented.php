<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Events;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a comment is added to a ticket (SRS FR-TKT-007; notification
 * matrix T3).
 *
 * The comment's `is_internal` flag travels with it because it is an
 * authorization fact, not a display preference: an internal note is a
 * conversation between staff about a requester's ticket, and notifying the
 * requester that one exists would leak the fact of it even if the text were
 * withheld.
 */
class TicketCommented
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketComment $comment,
        public readonly User $actor,
    ) {}
}
