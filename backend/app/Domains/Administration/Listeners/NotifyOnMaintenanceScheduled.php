<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Maintenance\Events\MaintenanceScheduled;
use App\Domains\Maintenance\Notifications\MaintenanceScheduledNotification;

/**
 * **Matrix T5 — maintenance scheduled.**
 *
 * Recipient: the technician the record names. The dispatcher drops the actor, so
 * a technician opening their own corrective job — the common case, and the whole
 * shape of technician-initiated maintenance under FR-MNT-009 — is told nothing,
 * which is correct. What survives the filter is the case that matters: an
 * administrator putting work on somebody else's list.
 *
 * Administrators are not copied. The matrix reserves the estate-wide view for
 * *due detection* (T5's second half, the daily sweep), and a notification to
 * every administrator every time any visit is opened would bury that signal
 * under the ordinary running of the desk.
 */
class NotifyOnMaintenanceScheduled
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(MaintenanceScheduled $event): void
    {
        $event->record->loadMissing('technician');

        $this->dispatcher->sendTo(
            $event->record->technician,
            new MaintenanceScheduledNotification($event->record),
            $event->actor,
        );
    }
}
