<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\Ticket;
use App\Models\User;

/**
 * **A reporter's NOT FIXED needs an owner** (Phase 2.9).
 *
 * Recipients are every active Administrator — assignment is theirs (FR-ASN-001)
 * — and nobody else: not the reporter (they caused it and see it on their own
 * ticket), not any technician (no one is assigned yet; that is the point).
 *
 * Structural facts only, per the project's content rule: the ticket, its number
 * and title, the room if known. The reporter's optional remarks are free text a
 * person typed, so they are not carried — the administrator reads them on the
 * ticket's own history, where they are shown to staff.
 */
class TicketEscalatedNotification extends ProjectNotification
{
    public function __construct(private readonly Ticket $ticket)
    {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TicketEscalated;
    }

    public function payload(User $notifiable): array
    {
        $room = $this->ticket->pcUnit->room ?? $this->ticket->room;

        return [
            'title' => "Ticket {$this->ticket->ticket_number} needs a technician",
            'message' => $this->ticket->title,
            'data' => array_filter([
                'ticket' => $this->ticket->uuid,
                'ticket_number' => $this->ticket->ticket_number,
                'outcome' => 'not_fixed',
                'room' => $room?->name,
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => "/app/tickets/manage/{$this->ticket->uuid}",
        ];
    }
}
