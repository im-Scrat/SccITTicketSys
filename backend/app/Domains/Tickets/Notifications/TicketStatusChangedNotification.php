<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\Ticket;
use App\Models\User;

/**
 * **T2 — a ticket you are part of moved** (SRS FR-TKT-005, FR-NOT-003).
 *
 * Recipients are the reporter and the assigned technician, never the actor.
 *
 * ── The teacher's projection is the binding constraint ─────────────────────
 *
 * A reporter is usually a Teacher, and DD-41 gives Teachers a **redacted**
 * ticket projection: no internal notes, no technician detail, no SLA posture.
 * A notification is a second surface onto the same record, so it has to obey the
 * same redaction — otherwise what the API carefully withholds simply arrives by
 * email instead.
 *
 * Two omissions follow, both deliberate rather than incidental:
 *
 *  - **the transition's remarks are not carried.** They are free text written by
 *    staff, and `TicketLifecycle` accepts them on internal moves as readily as
 *    on public ones. There is no per-remark visibility flag to consult, so the
 *    only safe reading is that they are staff notes.
 *  - **the actor is not named.** Identifying the technician who moved the ticket
 *    is exactly the "technician detail" the teacher's projection withholds.
 *
 * What is left — the ticket, and old status → new status — is what the reporter
 * already sees on their own ticket page.
 */
class TicketStatusChangedNotification extends ProjectNotification
{
    public function __construct(
        private readonly Ticket $ticket,
        private readonly ?string $fromLabel,
        private readonly string $toLabel,
        private readonly string $toSlug,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TicketStatusChanged;
    }

    public function payload(User $notifiable): array
    {
        return [
            'title' => "Ticket {$this->ticket->ticket_number} is now {$this->toLabel}",
            'message' => $this->ticket->title,
            'data' => array_filter([
                'ticket' => $this->ticket->uuid,
                'ticket_number' => $this->ticket->ticket_number,
                'from' => $this->fromLabel,
                'to' => $this->toLabel,
                'to_slug' => $this->toSlug,
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => "/app/tickets/{$this->ticket->uuid}",
        ];
    }
}
