<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\Ticket;
use App\Models\User;

/**
 * **T1, the other half — a ticket has been taken off you** (FR-NOT-003:
 * *"ticket assigned/reassigned"*).
 *
 * The matrix names the previously assigned technician as a recipient on
 * reassignment, and they need a different sentence from the person receiving the
 * work: one is being given a job, the other is being told to stop.
 *
 * Who took it over is deliberately absent. It does not change what the recipient
 * should do, and naming a colleague in a message about work being moved away
 * from someone reads as an accusation that the audit log has not made.
 */
class TicketUnassignedNotification extends ProjectNotification
{
    public function __construct(
        private readonly Ticket $ticket,
        private readonly int $assignmentId,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TicketReassigned;
    }

    /** The *new* assignment's row — one hand-off, one notification each way. */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->assignmentId;
    }

    public function payload(User $notifiable): array
    {
        return [
            'title' => "Ticket {$this->ticket->ticket_number} is no longer assigned to you",
            'message' => $this->ticket->title,
            'data' => [
                'ticket' => $this->ticket->uuid,
                'ticket_number' => $this->ticket->ticket_number,
            ],
            // A reassigned technician keeps read access (TicketVisibility's
            // READABLE_ASSIGNMENT_STATUSES), so the link still resolves for them.
            'action_url' => "/app/tickets/{$this->ticket->uuid}",
        ];
    }
}
