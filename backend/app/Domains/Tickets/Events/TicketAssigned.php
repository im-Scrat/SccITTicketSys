<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Events;

use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a ticket is assigned, or moved from one technician to another
 * (SRS FR-ASN-001/002/005; notification matrix T1).
 *
 * Carries **both** technicians because a reassignment is two facts to two
 * different people: the new holder needs to know they have work, and the
 * previous holder needs to know they no longer do. Deriving the second from the
 * assignment row afterwards would mean re-reading a row the action has already
 * closed.
 */
class TicketAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TechnicianAssignment $assignment,
        public readonly User $technician,
        public readonly ?User $previousTechnician,
        public readonly User $actor,
    ) {}

    public function isReassignment(): bool
    {
        return $this->previousTechnician !== null;
    }
}
