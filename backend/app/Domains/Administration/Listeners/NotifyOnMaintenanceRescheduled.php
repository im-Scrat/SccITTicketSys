<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Maintenance\Events\MaintenanceRescheduled;
use App\Domains\Maintenance\Notifications\MaintenanceRescheduledNotification;

/**
 * **Matrix T6 — maintenance rescheduled.**
 *
 * Recipient: the technician the record names, unless they moved it themselves.
 *
 * Both write paths reach this listener — an administrator editing the record
 * directly, and an administrator approving a work support request, which
 * reschedules the linked visit as part of the decision (FR-WSR-006). That second
 * path is why the notification exists at all: a technician who asked for a part
 * and got a new date is the person least likely to be watching the record.
 */
class NotifyOnMaintenanceRescheduled
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(MaintenanceRescheduled $event): void
    {
        $event->record->loadMissing('technician');

        $this->dispatcher->sendTo(
            $event->record->technician,
            new MaintenanceRescheduledNotification($event->record, $event->from, $event->to),
            $event->actor,
        );
    }
}
