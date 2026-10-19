<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\Ticket;
use App\Models\User;

/**
 * **T3 — a new comment on a ticket you are part of** (SRS FR-TKT-007,
 * FR-NOT-003; owner decision, 2026-09-05).
 *
 * ── What "followed" was decided to mean ────────────────────────────────────
 *
 * FR-NOT-003 asks for notifications on a *"followed/owned"* ticket, but no
 * follower, watcher or subscription table exists anywhere in the schema:
 * "owned" maps cleanly onto the reporter and the assignee, and "followed" maps
 * onto nothing at all. The Client's decision is to read "followed" as
 * **demonstrated participation** — anyone who has already commented on the
 * ticket — which needs no new entity and no UI to be truthful. Building a
 * follow/unfollow entity was the considered alternative and was declined as
 * larger than this work package. SRS reconciliation is owed and recorded.
 *
 * ── The body of the comment is not in here, and that is the point ──────────
 *
 * The recipient set spans all three roles, and the comment itself has an
 * `is_internal` flag whose whole purpose is that some comments are staff-only.
 * Rather than reasoning per recipient about which text each of them may see —
 * a decision that would then live in *two* places, here and in
 * `TicketCommentResource` — this notification carries no comment text at all,
 * and no author name either. It says a ticket has a new comment and links to
 * the ticket, where the existing authorization decides what is actually shown.
 *
 * The narrower rule is enforced one level up as well: the listener does not
 * send this to a Teacher when the comment is internal, because on an internal
 * note even the *fact* of it is staff-only.
 */
class TicketCommentedNotification extends ProjectNotification
{
    public function __construct(
        private readonly Ticket $ticket,
        private readonly string $commentUuid,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::TicketCommented;
    }

    /**
     * Keyed on the comment, so a retried job cannot announce one comment twice
     * and two comments cannot collapse into one announcement.
     */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->commentUuid;
    }

    public function payload(User $notifiable): array
    {
        return [
            'title' => "New comment on ticket {$this->ticket->ticket_number}",
            'message' => $this->ticket->title,
            'data' => [
                'ticket' => $this->ticket->uuid,
                'ticket_number' => $this->ticket->ticket_number,
                'comment' => $this->commentUuid,
            ],
            'action_url' => "/app/tickets/{$this->ticket->uuid}",
        ];
    }
}
