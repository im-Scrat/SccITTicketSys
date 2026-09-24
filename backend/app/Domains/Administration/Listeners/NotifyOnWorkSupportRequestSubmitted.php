<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationAudience;
use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\WorkSupport\Events\WorkSupportRequestSubmitted;
use App\Domains\WorkSupport\Notifications\WorkSupportRequestSubmittedNotification;

/**
 * **Matrix T9 — a work support request was submitted** (FR-WSR-012).
 *
 * Recipients: **Administrators**, which FR-NOT-003 states verbatim rather than
 * leaving to be inferred from who happens to hold a permission. Resolved by role
 * slug for that reason — see `NotificationAudience::administrators()`.
 *
 * This closes the first half of the debt WP-2.6b booked against itself: the
 * workflow shipped, was verified live for all three roles, and then sat in an
 * inbox nobody was told about.
 */
class NotifyOnWorkSupportRequestSubmitted
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationAudience $audience,
    ) {}

    public function handle(WorkSupportRequestSubmitted $event): void
    {
        $this->dispatcher->send(
            $this->audience->administrators(),
            new WorkSupportRequestSubmittedNotification(
                $event->request,
                $event->request->items()->count(),
            ),
            // An administrator may legitimately raise a request from a machine
            // they are working; they do not then need to be told about it.
            $event->technician,
        );
    }
}
