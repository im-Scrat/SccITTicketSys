<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Events;

use App\Domains\Tickets\Actions\ReportTicketNotFixed;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The reporter said NOT FIXED after the AI's suggestions (WP-J), so the ticket
 * now waits for an administrator to assign a technician (Phase 2.9).
 *
 * Fired by {@see ReportTicketNotFixed} once its transaction has committed. The
 * system escalates on the reporter's explicit answer; the AI never does — it
 * only suggested the steps the reporter tried.
 */
class TicketEscalatedByReporter
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $reporter,
    ) {}
}
