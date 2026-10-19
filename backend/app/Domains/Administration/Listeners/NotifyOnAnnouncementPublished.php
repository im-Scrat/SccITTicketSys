<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Events\AnnouncementPublished;
use App\Domains\Administration\Notifications\AnnouncementPublishedNotification;
use App\Domains\Administration\Services\NotificationAudience;
use App\Domains\Administration\Services\NotificationDispatcher;

/**
 * **Matrix T12 — an announcement was published** (SRS FR-NOT-003, FR-NOT-010).
 *
 * The only broadcast in the matrix. Every other trigger notifies people because
 * of something they did or something they own; this one notifies them because
 * of *who they are*, which is why the recipient set comes from the audience
 * column rather than from a visibility service reading a record's relations.
 *
 * ── The publishing administrator is excluded ──────────────────────────────
 *
 * WP-2.7c decision D9. `NotificationDispatcher::send()` drops the actor from every
 * recipient list, so an administrator publishing to `all` — or to `admins`,
 * where they are genuinely in the audience — is not told about their own
 * announcement. They can still read it on the reader page like anyone else;
 * they simply do not get a notification about the thing they just wrote.
 *
 * ── No email, and that is enforced by the notification, not here ───────────
 *
 * WP-2.7c decision D1. The exclusion lives on
 * {@see AnnouncementPublishedNotification::excludedChannels()} rather than in
 * this listener, so it holds for *every* path that ever dispatches that
 * notification — this listener today, and whatever calls it in future.
 */
class NotifyOnAnnouncementPublished
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationAudience $audience,
    ) {}

    public function handle(AnnouncementPublished $event): void
    {
        $recipients = $this->audience->forAnnouncementAudience($event->announcement->audience);

        $this->dispatcher->send(
            $recipients,
            new AnnouncementPublishedNotification($event->announcement, $event->renotified),
            $event->actor,
        );
    }
}
