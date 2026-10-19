<?php

declare(strict_types=1);

namespace App\Domains\Administration\Notifications\Channels;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\Administration\Providers\NotificationServiceProvider;
use App\Enums\NotificationType;
use App\Models\Notification as NotificationRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * **The project's own `database` notification channel** (SDD DD-52).
 *
 * Laravel's stock `Illuminate\Notifications\Channels\DatabaseChannel` writes a
 * morph (`notifiable_type` + `notifiable_id`), a uuid **primary key**, and a
 * `type` column holding the notification's *class name*. This project's
 * `notifications` table — baselined long before this work package — has a bigint
 * key with a separate public `uuid`, a direct `user_id`, and a `type` column
 * constrained by CHECK to the nine {@see NotificationType} values the
 * requirements enumerate (FR-NOT-005). The stock channel would fail on its first
 * insert here, and every column it wants is one the requirements do not have.
 *
 * DD-52's resolution is to replace the **driver**, not the schema: the framework
 * keeps its notification classes, its queue integration, and its read/unread
 * semantics; only the ~30 lines that decide what a row looks like are ours. That
 * is registered over the `database` channel name in
 * {@see NotificationServiceProvider}.
 *
 * ── Idempotency is enforced here, by the database ──────────────────────────
 *
 * Delivery is queued (`--tries=3`), so a worker can write the row and then die
 * before acknowledging the job. The retry arrives carrying the *same*
 * `dedupe_key` — it was serialized into the job payload — and Postgres refuses
 * the second insert on `notifications_dedupe_unique`. Catching 23505 and
 * returning the delivery as already-done is what makes at-least-once delivery
 * behave as exactly-once storage.
 *
 * A caller with no meaningful key sends null and the partial index ignores the
 * row entirely; nothing is silently deduplicated by accident.
 */
class DatabaseChannel
{
    /**
     * Write the in-app notification row.
     *
     * Returns null when the notification is not addressed at a real user, when
     * the notification is not one of ours, or when an identical delivery already
     * exists — three "nothing to do" cases the caller treats alike.
     */
    public function send(object $notifiable, Notification $notification): ?NotificationRecord
    {
        if (! $notifiable instanceof User || ! $notification instanceof ProjectNotification) {
            /*
             * Defensive rather than theoretical: `via()` is what routes a
             * notification here, and a future notification that returns
             * 'database' without extending our base class would otherwise write
             * a row with no title — which the NOT NULL constraint would reject
             * as a 500 deep inside a queue worker.
             */
            return null;
        }

        $payload = $notification->payload($notifiable);

        /*
         * The insert runs in its own transaction, and on Postgres that is not
         * decoration.
         *
         * A unique-constraint violation **aborts the enclosing transaction**
         * (SQLSTATE 25P02): every subsequent statement on that connection is
         * refused until it is rolled back. So catching 23505 without isolating
         * the insert would "swallow" the duplicate and leave the caller holding
         * a connection on which nothing else can run — a far worse failure than
         * the duplicate it was trying to tolerate.
         *
         * `DB::transaction()` opens a SAVEPOINT when a transaction is already
         * in progress, so the violation rolls back to exactly this insert and
         * the caller's transaction survives intact. Where no transaction is
         * open — the ordinary queue-worker case — it is one BEGIN/COMMIT.
         */
        try {
            return DB::transaction(fn (): NotificationRecord => NotificationRecord::query()->create([
                'user_id' => $notifiable->getKey(),
                'type' => $notification->topic()->type()->value,
                'title' => $payload['title'],
                'message' => $payload['message'],
                'data' => [
                    // The trigger identity travels with the row so WP-2.7b can
                    // group and filter by it without the nine-value `type`
                    // column having to carry a distinction it cannot express.
                    'topic' => $notification->topic()->value,
                    ...$payload['data'],
                ],
                'action_url' => $payload['action_url'],
                'dedupe_key' => $notification->dedupeKey(),
                'created_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                // The row is already there from an earlier attempt. The job
                // succeeded; it just did not need to do anything this time.
                return null;
            }

            throw $exception;
        }
    }

    /** Postgres reports a unique-constraint violation as SQLSTATE 23505. */
    private function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23505';
    }
}
