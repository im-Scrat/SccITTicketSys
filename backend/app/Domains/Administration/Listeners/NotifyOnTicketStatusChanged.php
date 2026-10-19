<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Tickets\Events\TicketStatusChanged;
use App\Domains\Tickets\Notifications\TicketStatusChangedNotification;

/**
 * **Matrix T2 — ticket status change.**
 *
 * Recipients: the reporter, and the assigned technician when they are not the
 * one who moved it. Both are people `TicketVisibility` already grants the full
 * projection to, so neither is being shown a ticket they could not open.
 *
 * The scheduled auto-close (FR-TKT-016) arrives here with a null actor, and that
 * is a case worth handling rather than guarding against: nobody is excluded, and
 * both the reporter and the technician are told the system closed it — which is
 * precisely the transition a person is most likely to be surprised by.
 */
class NotifyOnTicketStatusChanged
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(TicketStatusChanged $event): void
    {
        $ticket = $event->ticket;
        $ticket->loadMissing(['reporter', 'assignedTechnician']);

        $this->dispatcher->send(
            [$ticket->reporter, $ticket->assignedTechnician],
            new TicketStatusChangedNotification(
                $ticket,
                $event->from?->name,
                $event->to->name,
                $event->to->slug,
            ),
            $event->actor,
        );
    }
}
