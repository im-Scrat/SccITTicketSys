<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\WorkSupport\Events\WorkSupportRequestDecided;
use App\Domains\WorkSupport\Notifications\WorkSupportRequestDecidedNotification;

/**
 * **Matrix T10 — an administrator decided a work support request**
 * (FR-WSR-012).
 *
 * Recipient: the **submitting technician**, named verbatim by FR-NOT-003 and
 * resolved from `work_support_requests.technician_id` — the same column
 * `WorkSupportVisibility::scopeOwn()` uses, so the person notified is by
 * construction the person who may read the request.
 *
 * The second half of the WP-2.6b carry-forward, and the one that completes the
 * loop: a technician raises a request at a machine, walks away, and now learns
 * the answer instead of having to go back and look.
 */
class NotifyOnWorkSupportRequestDecided
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(WorkSupportRequestDecided $event): void
    {
        $event->request->loadMissing('technician');

        $this->dispatcher->sendTo(
            $event->request->technician,
            new WorkSupportRequestDecidedNotification($event->request, $event->decision),
            $event->actor,
        );
    }
}
