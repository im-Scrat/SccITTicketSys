<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use App\Domains\Administration\Notifications\Concerns\RetriesMailDelivery;
use App\Domains\Administration\Notifications\ProjectNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Laravel's password-reset email, queued — for the same reason as
 * {@see AccountMail}: a slow relay must not hang `/forgot-password`, and an
 * outage must retry rather than fail.
 *
 * Content and link are exactly the framework's, including the SPA URL set by
 * `ResetPassword::createUrlUsing()` in AppServiceProvider (which every queue
 * worker also boots). Queuing has a privacy bonus: the response time of
 * `/forgot-password` no longer differs between an address that exists (a mail
 * is sent) and one that does not.
 *
 * The token stays valid for `auth.passwords.users.expire` minutes (60), far
 * longer than the retry window (1 + 5 + 15).
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueueAfterCommit
{
    use Queueable, RetriesMailDelivery;

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => ProjectNotification::NOTIFICATIONS_QUEUE];
    }
}
