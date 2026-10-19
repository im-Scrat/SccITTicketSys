<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Tickets\Events\TicketCommented;
use App\Domains\Tickets\Notifications\TicketCommentedNotification;
use App\Models\TicketComment;
use App\Models\User;

/**
 * **Matrix T3 — new comment on a followed / owned ticket.**
 *
 * ── The recipient rule the Client chose ────────────────────────────────────
 *
 * *"Followed"* has no schema behind it — there is no follower, watcher or
 * subscription table anywhere — so the Client decided (2026-09-05) to read it as
 * **demonstrated participation**: the reporter, the assigned technician, and
 * anyone who has already commented. Nothing is invented and nothing new is
 * stored; participation is a fact the `ticket_comments` rows already record.
 *
 * ── Internal notes do not reach a requester, at all ────────────────────────
 *
 * `ticket_comments.is_internal` exists so staff can discuss a ticket in front of
 * it without the requester reading along. On such a comment the *existence* of
 * the note is itself staff-only — "there is a new comment you cannot see" tells
 * a requester something the flag was created to withhold — so the recipient list
 * is narrowed to Administrators and Technicians rather than the notification
 * being redacted. The notification body carries no comment text in either case;
 * this narrowing is the second, independent guard.
 *
 * Prior commenters are read straight from the ticket, including the soft-deleted
 * ones' authors — a person whose comment was later removed still participated in
 * the conversation and is still following it.
 */
class NotifyOnTicketCommented
{
    /**
     * A bound on how far back participation is resolved.
     *
     * A long-running ticket can accumulate a great many comments, and the
     * recipient set is the *distinct authors*, which is far smaller — but the
     * query still has to read the rows. Capping the scan keeps a busy ticket
     * from turning one comment into an unbounded fan-out, and the people who
     * commented most recently are the ones still watching.
     */
    private const PARTICIPANT_SCAN_LIMIT = 200;

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(TicketCommented $event): void
    {
        $ticket = $event->ticket;
        $ticket->loadMissing(['reporter', 'assignedTechnician']);

        $recipients = [
            $ticket->reporter,
            $ticket->assignedTechnician,
            ...$this->priorCommenters($event),
        ];

        if ($event->comment->is_internal) {
            $recipients = array_filter($recipients, $this->isStaff(...));
        }

        $this->dispatcher->send(
            $recipients,
            new TicketCommentedNotification($ticket, $event->comment->uuid),
            $event->actor,
        );
    }

    /**
     * Everyone who has commented on this ticket before, most recent first.
     *
     * @return list<User>
     */
    private function priorCommenters(TicketCommented $event): array
    {
        $ids = TicketComment::query()
            ->where('ticket_id', $event->ticket->getKey())
            ->whereKeyNot($event->comment->getKey())
            ->orderByDesc('id')
            ->limit(self::PARTICIPANT_SCAN_LIMIT)
            ->pluck('user_id')
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereKey($ids)->get()->all();
    }

    private function isStaff(?User $user): bool
    {
        return in_array($user?->role?->slug, ['administrator', 'technician'], true);
    }
}
