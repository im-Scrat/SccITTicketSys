<?php

declare(strict_types=1);

namespace App\Domains\Administration\Policies;

use App\Domains\Administration\Services\AnnouncementVisibility;
use App\Models\Announcement;
use App\Models\User;

/**
 * Per-record authorization for announcements (SRS FR-NOT-010/011; WP-2.7c).
 *
 * ── Two questions, and they are not the same question ──────────────────────
 *
 * **Managing** an announcement is a single administrative ability:
 * `system.announcements.manage`, already seeded to Administrators in the
 * Client's §8.4 matrix. WP-2.7c deliberately did **not** split it into
 * create/update/delete — decision D5 — because splitting it would change a
 * matrix the Client has approved, to draw a line no requirement asks for.
 *
 * **Reading** an announcement is a different question with a different answer,
 * and it is not a permission at all: it is *audience membership*, delegated to
 * {@see AnnouncementVisibility} so the list rule and the single-record rule are
 * literally the same rule (the DD-40 shape). A teacher who guesses the uuid of
 * an announcement aimed at technicians is refused here, not merely shown
 * nothing — audience targeting is an authorization boundary, not a display
 * filter.
 *
 * ── Why an administrator is not simply allowed everything ──────────────────
 *
 * {@see view()} answers for the **reader** surface, where an administrator is
 * just another audience member: their own announcements page should show what
 * an administrator was told, not the estate. The management surface asks
 * {@see manage()} instead and sees everything, drafts and expired rows
 * included. Collapsing the two would make the reader page silently different
 * for one role and would hide targeting mistakes from the person most able to
 * fix them.
 */
class AnnouncementPolicy
{
    public function __construct(private readonly AnnouncementVisibility $visibility) {}

    /**
     * The management surface — list, create, edit, publish, delete, notify.
     *
     * One ability covers all of them by design (D5).
     */
    public function manage(User $user): bool
    {
        return $user->hasPermissionTo('system.announcements.manage');
    }

    /** Reading one announcement: audience membership, not permission. */
    public function view(User $user, Announcement $announcement): bool
    {
        return $this->visibility->canRead($user, $announcement);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->manage($user);
    }

    /**
     * Re-notify an audience about an announcement they may already have been
     * told about (decision D7).
     *
     * Same ability as any other management action — the protection against a
     * surprise blast is that it is a **separate, explicit** operation, not that
     * it is harder to authorize.
     */
    public function notify(User $user, Announcement $announcement): bool
    {
        return $this->manage($user);
    }
}
