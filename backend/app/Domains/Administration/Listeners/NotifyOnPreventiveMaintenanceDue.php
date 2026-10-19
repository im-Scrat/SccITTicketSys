<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationAudience;
use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\Maintenance\Events\PreventiveMaintenanceDue;
use App\Domains\Maintenance\Notifications\MaintenanceDueNotification;

/**
 * **Matrix T5, second half — maintenance due / overdue.**
 *
 * Recipients: the assigned technician **and** the administrators. The matrix
 * asks for both and the reason is structural rather than generous — a visit can
 * legitimately have no technician on it, and a reminder with nobody to send it
 * to is a reminder that does not exist. The administrators are the backstop that
 * makes the sweep meaningful for unassigned work.
 *
 * The daily cadence is bounded by the notification's own dedupe key rather than
 * by anything here: at most one row per record per recipient per day, plus one
 * more on the day it tips from *due soon* to *overdue*. That is what lets this
 * listener stay a plain fan-out and the sweep stay a plain re-derivation of the
 * same predicate the dashboard uses.
 */
class NotifyOnPreventiveMaintenanceDue
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationAudience $audience,
    ) {}

    public function handle(PreventiveMaintenanceDue $event): void
    {
        $event->record->loadMissing('technician');

        $this->dispatcher->send(
            [
                $event->record->technician,
                ...$this->audience->administrators(),
            ],
            new MaintenanceDueNotification($event->record, $event->overdue),
        );
    }
}
