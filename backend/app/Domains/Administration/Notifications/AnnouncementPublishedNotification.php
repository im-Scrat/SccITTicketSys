<?php

declare(strict_types=1);

namespace App\Domains\Administration\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\Announcement;
use App\Models\User;

/**
 * **T12 — an announcement was published** (SRS FR-NOT-003, FR-NOT-010;
 * Client decision D5).
 *
 * ── The only notification in the system that refuses a channel ─────────────
 *
 * D5 (SRS §22.1) is that publishing an announcement creates an **in-app
 * notification and sends no email**. Every other trigger in the matrix lets
 * the preference gate decide where it goes; this one cannot, and the reason
 * is arithmetic rather than taste. Preferences are opt-out — an absent row
 * means *enabled* — so on a fresh system the gate would allow email for every
 * recipient, and a single `all` announcement would mail the entire
 * organization. A guarantee that depends on every user having opted out is
 * not a guarantee.
 *
 * So this class names `Email` in {@see excludedChannels()}, which refuses the
 * channel before the gate is consulted (WP-2.7c-1). The user's in-app
 * preference for the `announcement` type still governs normally: a person who
 * has switched announcements off in-app is still not notified. What they cannot
 * do — and what an administrator cannot cause — is receive it by mail.
 *
 * ── Content is the title, never the body ───────────────────────────────────
 *
 * DD-61: notifications carry structural facts, not borrowed prose. The
 * announcement's `content` is deliberately absent from the payload even though
 * it is plain text and would fit. The notification says *that* something was
 * announced and links to it; the announcement itself is read on the reader
 * page. That keeps this consistent with every other trigger and means a future
 * change to what an announcement may contain cannot silently widen what a
 * notification exports.
 */
class AnnouncementPublishedNotification extends ProjectNotification
{
    public function __construct(
        private readonly Announcement $announcement,
        /** Distinguishes a deliberate repeat from the first publication (D7). */
        private readonly bool $renotified = false,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::AnnouncementPublished;
    }

    /**
     * **In-app only. Never email** — D5 (SRS §22.1), by the WP-2.7c D1
     * mechanism.
     *
     * @return list<NotificationChannel>
     */
    public function excludedChannels(): array
    {
        return [NotificationChannel::Email];
    }

    /**
     * One notification per announcement per recipient — unless an administrator
     * explicitly asked to notify again.
     *
     * Publishing is idempotent on the announcement's uuid, so a retried job, a
     * double-clicked button or a re-published announcement cannot leave a user
     * with two rows saying the same thing. A deliberate re-notification
     * (decision D7) carries its own suffix, because there the *point* is that
     * the audience is told a second time.
     */
    public function dedupeKey(): ?string
    {
        $key = $this->topic()->value.':'.$this->announcement->uuid;

        return $this->renotified
            ? $key.':renotified:'.$this->stamp()->dispatchId
            : $key;
    }

    public function payload(User $notifiable): array
    {
        return [
            'title' => $this->announcement->title,
            // Deliberately not the announcement body — see the class note.
            'message' => $this->renotified ? 'Announcement re-sent by an administrator.' : null,
            'data' => [
                'announcement' => $this->announcement->uuid,
                'pinned' => (bool) $this->announcement->is_pinned,
            ],
            // An internal destination, validated client-side against the `/app/`
            // prefix before it is ever followed (DD-65).
            'action_url' => '/app/announcements/'.$this->announcement->uuid,
        ];
    }
}
