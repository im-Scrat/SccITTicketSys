<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\Ticket;
use App\Models\User;

/**
 * **T1 — you have been given a ticket** (SRS FR-ASN-005, FR-NOT-003).
 *
 * Goes to the newly assigned technician, who by construction may read the whole
 * ticket: `TicketVisibility` grants the full projection to anyone holding an
 * assignment row, so every field below is one the recipient could already fetch.
 * That is the test each notification in this work package has to pass — *would
 * this recipient be shown this if they asked the API for it?*
 *
 * Priority and room are included because they are what decides whether the
 * technician walks over now or after lunch, and both are in the assignee's own
 * projection. The reporter's name is **not**: it adds nothing to that decision,
 * and it is a person's identity travelling in a payload that is also mirrored
 * to email.
 */
class TicketAssignedNotification extends ProjectNotification
{
    public function __construct(
        private readonly Ticket $ticket,
        private readonly int $assignmentId,
        private readonly bool $reassignment,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TicketAssigned;
    }

    /**
     * Keyed on the **assignment row**, not on the dispatch.
     *
     * FR-ASN-002 allows exactly one active assignment per ticket, enforced by a
     * partial unique index, so the assignment row is a natural once-per-event
     * identity. Two administrators racing to assign the same ticket produce one
     * surviving row and therefore one notification, even though both attempts
     * reached this far.
     *
     * By primary key rather than uuid, because `technician_assignments` has
     * none — see the note in `NotifyOnTicketAssigned` for why that is safe.
     */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->assignmentId;
    }

    public function payload(User $notifiable): array
    {
        $this->ticket->loadMissing(['priority', 'room']);

        return [
            'title' => $this->reassignment
                ? "Ticket {$this->ticket->ticket_number} reassigned to you"
                : "Ticket {$this->ticket->ticket_number} assigned to you",
            'message' => $this->ticket->title,
            'data' => array_filter([
                'ticket' => $this->ticket->uuid,
                'ticket_number' => $this->ticket->ticket_number,
                'priority' => $this->ticket->priority?->slug,
                'room' => $this->ticket->room?->name,
                'reassignment' => $this->reassignment,
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => "/app/tickets/{$this->ticket->uuid}",
        ];
    }

    /**
     * @param  array{title: string, message: string|null, data: array<string, mixed>, action_url: string|null}  $payload
     * @return list<string>
     */
    protected function mailLines(array $payload): array
    {
        // The ticket *title* is requester-authored free text, so it stays in the
        // in-app row and out of the mail body — see ProjectNotification.
        return [
            $payload['title'].'.',
            isset($payload['data']['priority'])
                ? 'Priority: '.$payload['data']['priority'].'.'
                : 'Open the ticket for its priority and location.',
        ];
    }
}
