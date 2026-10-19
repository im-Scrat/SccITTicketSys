<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Administration\Notifications\Concerns\RetriesMailDelivery;
use App\Domains\Administration\Notifications\ProjectNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * Base for the account emails — registration received, approved, rejected, and
 * an administrator's bulk message.
 *
 * These were sent synchronously, on the request thread, which was harmless
 * while the transport was Mailpit on the same Docker network (SDD §10.3). With
 * a real relay it is not: a slow or failing SMTP server turned registration,
 * approval and rejection into a hanging request or a 500 *after* the database
 * change had committed, and a bulk message sent inside its transaction could
 * go out even when that transaction rolled back.
 *
 * Now each one is queued on the `notifications` queue (the same worker as every
 * other notification) and dispatched only after the surrounding transaction
 * commits, so a rolled-back action sends nothing and a relay outage becomes a
 * retry ({@see RetriesMailDelivery}) instead of an error page.
 */
abstract class AccountMail extends Notification implements ShouldQueueAfterCommit
{
    use Queueable, RetriesMailDelivery;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => ProjectNotification::NOTIFICATIONS_QUEUE];
    }
}
