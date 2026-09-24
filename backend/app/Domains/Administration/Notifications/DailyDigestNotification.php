<?php

declare(strict_types=1);

namespace App\Domains\Administration\Notifications;

use App\Domains\Administration\Services\DigestWindow;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * **The daily digest email** (SRS FR-NOT-008; SDD DD-63).
 *
 * ── Why this does not extend `ProjectNotification` ────────────────────────
 *
 * Everything under `ProjectNotification` is *a notification*: it has a topic,
 * writes a row, carries an idempotency key and asks the preference gate where
 * to go. A digest is none of those things — it is a summary of rows that
 * already exist. Extending that base would have meant inheriting a `via()` that
 * consults the gate per channel, a `database` arm that would write a
 * notification about notifications, and a `topic()` that has no honest answer.
 *
 * So this is a plain Laravel notification with one channel. The preference
 * question was already asked, per type, by `DailyDigest::itemsFor()` before
 * this class is ever constructed — which is what keeps the digest inside the
 * existing preference architecture rather than beside it.
 *
 * ── Not queued, deliberately ──────────────────────────────────────────────
 *
 * `ProjectNotification` is `ShouldQueue` so a request thread never blocks on
 * mail. This runs in a scheduled command where there is no request to protect,
 * and where being synchronous is the point: the command must know whether SMTP
 * accepted the message. Queuing it would put the send behind
 * `--tries=3`, and a retried digest is precisely what the at-most-once record
 * exists to prevent.
 *
 * ── Titles only ───────────────────────────────────────────────────────────
 *
 * DD-61 confines notifications to structural facts, and
 * `ProjectNotification::mailLines()` already refuses to mail
 * `payload['message']` because that is where quoted human text lives. A digest
 * is many notifications at once, so the same rule binds harder, not softer:
 * this class reads `title` and nothing else. The body of a comment, a decline
 * reason or a technician's note never reaches a mailbox through here.
 */
class DailyDigestNotification extends Notification
{
    /**
     * @param  Collection<int, NotificationRow>  $items
     */
    public function __construct(
        private readonly Collection $items,
        private readonly DigestWindow $window,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $count = $this->items->count();

        $mail = (new MailMessage)
            ->subject("SccIT: your daily summary for {$this->window->label()}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line($count === 1
                ? "You have 1 unread notification from {$this->window->label()}."
                : "You have {$count} unread notifications from {$this->window->label()}.");

        foreach ($this->items as $item) {
            // `title` only. Never `message`, never `data` — see the class note.
            $mail->line('• '.$item->title);
        }

        return $mail
            ->action('Open your notifications', url('/app/notifications'))
            ->line('You are receiving this because the daily digest is switched on in your notification preferences.');
    }
}
