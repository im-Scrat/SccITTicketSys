<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Events;

use App\Domains\Tickets\Services\TicketLifecycle;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised by {@see TicketLifecycle} on every
 * accepted transition (SRS FR-TKT-005; notification matrix T2).
 *
 * `$actor` is nullable and that is meaningful rather than defensive: the
 * scheduled auto-close (FR-TKT-016) moves tickets with nobody behind it, and a
 * system-driven transition still has to reach the reporter.
 */
class TicketStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?TicketStatus $from,
        public readonly TicketStatus $to,
        public readonly ?User $actor,
    ) {}
}
