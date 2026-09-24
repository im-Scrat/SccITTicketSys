<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationAudience;
use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Tickets\Events\TicketSlaThresholdCrossed;
use App\Domains\Tickets\Notifications\TicketSlaThresholdNotification;

/**
 * **Matrix T4 — SLA breach / nearing breach.**
 *
 * Recipients: **all administrators, plus the assigned technician** — the
 * Client's decision of 2026-09-05, taken because the SRS names no recipients and
 * OI-06 (automatic escalation) is still open. Oversight for the people
 * accountable for the desk; a prompt for the one person who can act.
 *
 * The reporter is not on this list, and that is the deliberate half. DD-41
 * withholds SLA posture from a Teacher's ticket projection, so notifying a
 * requester that their ticket has breached would disclose by another route
 * exactly what the projection is written to withhold — and would do it at the
 * moment the desk is least able to answer for it.
 *
 * No actor is excluded because there is none: a deadline passing is not an act.
 */
class NotifyOnTicketSlaThreshold
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationAudience $audience,
    ) {}

    public function handle(TicketSlaThresholdCrossed $event): void
    {
        $event->ticket->loadMissing('assignedTechnician');

        $this->dispatcher->send(
            [
                ...$this->audience->administrators(),
                $event->ticket->assignedTechnician,
            ],
            new TicketSlaThresholdNotification(
                $event->ticket,
                $event->stage,
                $event->isBreach(),
                $event->clock(),
            ),
        );
    }
}
