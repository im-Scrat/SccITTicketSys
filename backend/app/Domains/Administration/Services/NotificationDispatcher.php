<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Domains\Administration\Notifications\Channels\DatabaseChannel;
use App\Domains\Administration\Notifications\ProjectNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * **The single sanctioned way to send a notification** (WP-2.7a).
 *
 * Every listener, action and console command goes through here. It exists to
 * hold one guarantee that is easy to state and easy to lose:
 *
 * > **Nothing about notifying anybody may change the outcome of the thing that
 * > caused the notification.**
 *
 * A ticket assignment that succeeded must not become a 500 because Redis is
 * down. A work support request that was written must not be rolled back because
 * one recipient's row violated a constraint. The business action already
 * happened; telling people about it is a consequence, not a precondition.
 *
 * Three mechanisms enforce that, in order:
 *
 * ── 1. Dispatch waits for the commit ──────────────────────────────────────
 *
 * {@see DB::afterCommit()} runs the callback immediately when no transaction is
 * open and defers it to commit when one is — and drops it entirely on rollback.
 * So a notification is never queued about a row that was not written, and the
 * try/catch below is *inside* the callback, which is what makes the isolation
 * hold in both cases. Deferring with the notification's own `$afterCommit` flag
 * alone would not: that failure would surface in Laravel's commit callback,
 * outside any handler of ours, and turn a successful write into an error
 * response.
 *
 * ── 2. Recipients are isolated from each other ────────────────────────────
 *
 * One `send()` per recipient rather than one call with a collection, so a
 * failure for one person does not silently truncate the list for everybody
 * after them.
 *
 * ── 3. The queue is not a single point of failure for the durable half ────
 *
 * If pushing the job fails — Redis unreachable, which is the realistic
 * outage — the in-app row is written **synchronously** as a fallback, through
 * the same channel driver and the same idempotency key. The notification centre
 * therefore keeps working while the queue is down; only email is lost, and
 * email is the channel a user can least afford to have blocking their request
 * anyway. When the queue recovers there is nothing to replay, because the
 * dedupe key means the retried job would write nothing new.
 *
 * What this deliberately does **not** have is a durable outbox table. That would
 * be a new entity, and the residual exposure it would close is narrow: a
 * notification lost only if the queue push *and* the synchronous fallback both
 * fail, which means Redis and Postgres are both unavailable — at which point the
 * business action did not happen either.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationAudience $audience,
        private readonly DatabaseChannel $channel,
    ) {}

    /**
     * Notify a set of recipients, minus the actor, minus inactive accounts,
     * minus duplicates.
     *
     * Returns the number of recipients the notification was handed to — which
     * is a count of *attempts accepted*, not of rows written: the preference
     * gate may still route a recipient to no channel at all, and that is a
     * correct outcome rather than a failure.
     *
     * @param  iterable<User|null>  $recipients
     * @param  User|null  $actor  never notified of their own action
     */
    public function send(iterable $recipients, ProjectNotification $notification, ?User $actor = null): int
    {
        $targets = $this->audience->except($recipients, $actor);

        if ($targets === []) {
            return 0;
        }

        $notification->stamp();

        DB::afterCommit(function () use ($targets, $notification): void {
            foreach ($targets as $target) {
                $this->deliver($target, $notification);
            }
        });

        return count($targets);
    }

    /** Convenience for the common single-recipient trigger. */
    public function sendTo(?User $recipient, ProjectNotification $notification, ?User $actor = null): int
    {
        return $this->send([$recipient], $notification, $actor);
    }

    /**
     * One recipient, fully isolated.
     */
    private function deliver(User $target, ProjectNotification $notification): void
    {
        try {
            Notification::send([$target], $notification);
        } catch (Throwable $exception) {
            report($exception);
            $this->fallback($target, $notification);
        }
    }

    /**
     * Write the in-app row directly when the queue could not take the job.
     *
     * Only attempted when the recipient's preferences actually route this
     * notification in-app — falling back to a channel the user switched off
     * would use an outage as an excuse to ignore their choice.
     */
    private function fallback(User $target, ProjectNotification $notification): void
    {
        if (! in_array('database', $notification->via($target), true)) {
            return;
        }

        try {
            $this->channel->send($target, $notification);

            Log::warning('Notification queued delivery failed; in-app row written synchronously.', [
                'topic' => $notification->topic()->value,
                'user' => $target->uuid,
            ]);
        } catch (Throwable $exception) {
            // Both paths are gone. Report and continue: the business action
            // that caused this has already succeeded and must stay succeeded.
            report($exception);
        }
    }
}
