<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Administration\Events\AnnouncementPublished;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every write an announcement can undergo (SRS FR-NOT-010; WP-2.7c).
 *
 * ── Publishing is an operation, not a column the client sets ───────────────
 *
 * The same shape `TicketLifecycle` and `WorkSupportRequestLifecycle` already
 * use (DD-43, DD-54): `is_active` is never accepted from a request body. It
 * moves through {@see publish()} and {@see unpublish()}, which are the only
 * places that can raise {@see AnnouncementPublished} — so a notification cannot
 * be produced by a `PUT` that happened to include a flag, and the audit trail
 * records an intention rather than a field change.
 *
 * ── Editing does not re-notify (decision D7) ───────────────────────
 *
 * {@see update()} raises no event, whatever it changes and however live the
 * announcement is. An administrator fixing a typo must not blast the school a
 * second time. When they genuinely want to, {@see notifyAgain()} is a separate,
 * explicit and separately audited operation — the protection is that the repeat
 * is *chosen*, not that it is forbidden.
 *
 * Each write is one transaction covering the row and its activity log, so an
 * announcement can never be published without the audit entry that says who did
 * it.
 */
class ManageAnnouncement
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): Announcement
    {
        return DB::transaction(function () use ($actor, $attributes): Announcement {
            $announcement = Announcement::query()->create([
                ...$this->writable($attributes),
                'created_by' => $actor->getKey(),
                // A new announcement is a draft. Publishing is a decision, and
                // it is the decision that notifies an audience.
                'is_active' => false,
            ]);

            $this->log($actor, $announcement, ActivityAction::AnnouncementCreated);

            return $announcement;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Announcement $announcement, array $attributes): Announcement
    {
        return DB::transaction(function () use ($actor, $announcement, $attributes): Announcement {
            $announcement->fill($this->writable($attributes))->save();

            // Deliberately no AnnouncementPublished here — decision D7.
            $this->log($actor, $announcement, ActivityAction::AnnouncementUpdated);

            return $announcement->refresh();
        });
    }

    /**
     * Make an announcement live and tell its audience.
     *
     * Idempotent in the sense that matters: re-publishing an already-live
     * announcement raises the event again, but the notification's dedupe key is
     * the announcement's uuid, so no recipient receives a second row. That is
     * what makes a double-clicked button harmless without making the operation
     * refuse to run.
     */
    public function publish(User $actor, Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($actor, $announcement): Announcement {
            $announcement->forceFill(['is_active' => true])->save();

            $this->log($actor, $announcement, ActivityAction::AnnouncementPublished);

            AnnouncementPublished::dispatch($announcement, $actor);

            return $announcement->refresh();
        });
    }

    /** Withdraw an announcement from the reader surface. Notifies nobody. */
    public function unpublish(User $actor, Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($actor, $announcement): Announcement {
            $announcement->forceFill(['is_active' => false])->save();

            $this->log($actor, $announcement, ActivityAction::AnnouncementUnpublished);

            return $announcement->refresh();
        });
    }

    /**
     * Deliberately notify the audience again (decision D7).
     *
     * Separate from {@see publish()} so the audit trail can tell a first
     * publication from a repeat, and so the repeat carries its own idempotency
     * suffix — here the point *is* that people are told a second time.
     */
    public function notifyAgain(User $actor, Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($actor, $announcement): Announcement {
            $this->log($actor, $announcement, ActivityAction::AnnouncementRenotified);

            AnnouncementPublished::dispatch($announcement, $actor, true);

            return $announcement;
        });
    }

    /** Soft delete: the row stays for the audit trail, the audience loses it. */
    public function delete(User $actor, Announcement $announcement): void
    {
        DB::transaction(function () use ($actor, $announcement): void {
            $this->log($actor, $announcement, ActivityAction::AnnouncementDeleted);

            $announcement->delete();
        });
    }

    /**
     * The fields a request may set.
     *
     * `is_active` is absent by design — see the class note. `created_by` is set
     * once, by {@see create()}, from the session.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function writable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'title',
            'content',
            'audience',
            'starts_at',
            'ends_at',
            'is_pinned',
        ]));
    }

    private function log(User $actor, Announcement $announcement, ActivityAction $action): void
    {
        $this->audit->activity(
            action: $action,
            actor: $actor,
            subject: $announcement,
            properties: [
                'title' => $announcement->title,
                'audience' => $announcement->audience->value,
                'is_pinned' => (bool) $announcement->is_pinned,
            ],
            module: 'administration',
        );
    }
}
