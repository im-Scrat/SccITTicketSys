<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Tickets\Events\TicketAssigned;
use App\Domains\Tickets\Notifications\TicketAssignedNotification;
use App\Domains\Tickets\Notifications\TicketUnassignedNotification;

/**
 * **Matrix T1 — ticket assigned / reassigned.**
 *
 * Two recipients on a reassignment and one on a first assignment, each getting a
 * different message. The administrator who performed the assignment is excluded
 * by the dispatcher, which drops the actor from every list.
 *
 * ── Why the listeners live in Administration rather than in Tickets ────────
 *
 * `TicketCreated` said it when it was written: an event exists so that a later
 * phase *registers a listener* instead of editing the write path everyone
 * depends on. Keeping every listener in this one folder means the answer to
 * "who gets told what, and what may it contain" has a single place to be read
 * and audited — which is the security review this work package actually has to
 * pass. Scattering them across four domains would put that answer in four
 * places and guarantee the fifth trigger disagrees with the first.
 */
class NotifyOnTicketAssigned
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(TicketAssigned $event): void
    {
        /*
         * The assignment's **primary key**, not a uuid: `technician_assignments`
         * has no uuid column — it is a join-shaped record that is never
         * addressed publicly, so Phase 2.6 gave it none. That is safe here
         * because the value only ever reaches `notifications.dedupe_key`, which
         * `NotificationResource` deliberately does not expose (NFR-SEC-001).
         */
        $assignmentId = (int) $event->assignment->getKey();

        $this->dispatcher->sendTo(
            $event->technician,
            new TicketAssignedNotification($event->ticket, $assignmentId, $event->isReassignment()),
            $event->actor,
        );

        if ($event->previousTechnician !== null) {
            $this->dispatcher->sendTo(
                $event->previousTechnician,
                new TicketUnassignedNotification($event->ticket, $assignmentId),
                $event->actor,
            );
        }
    }
}
