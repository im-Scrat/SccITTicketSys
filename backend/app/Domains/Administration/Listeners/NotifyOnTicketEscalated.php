<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationAudience;
use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Tickets\Events\TicketEscalatedByReporter;
use App\Domains\Tickets\Notifications\TicketEscalatedNotification;

/**
 * Phase 2.9 — NOT FIXED goes to the people who can act on it: every active
 * Administrator, and no one else. The reporter is passed as the actor so the
 * dispatcher's actor exclusion holds even for an Administrator who reported
 * their own ticket.
 */
class NotifyOnTicketEscalated
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationAudience $audience,
    ) {}

    public function handle(TicketEscalatedByReporter $event): void
    {
        $event->ticket->loadMissing(['pcUnit.room', 'room']);

        $this->dispatcher->send(
            $this->audience->administrators(),
            new TicketEscalatedNotification($event->ticket),
            $event->reporter,
        );
    }
}
