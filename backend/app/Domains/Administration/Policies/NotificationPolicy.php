<?php

declare(strict_types=1);

namespace App\Domains\Administration\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * **Who may read a notification** (SRS FR-NOT-001/004; NFR-SEC-003).
 *
 * The simplest authorization boundary in the application, and the one it would
 * be easiest to get wrong by being clever about it: **a notification belongs to
 * exactly one person, and only that person may touch it.**
 *
 * ── There is no administrator override, deliberately ───────────────────────
 *
 * Every other module in this system gives Administrators the whole estate:
 * `TicketVisibility`, `MaintenanceVisibility` and `WorkSupportVisibility` all
 * open completely for the administrator role, because running the desk means
 * seeing the work. A notification is not work — it is a message addressed to a
 * person, and its `data` payload can carry material drawn from a ticket, a
 * machine or a colleague's request. Letting an administrator read another user's
 * notification list would be a second, unaudited route into records the first
 * route scopes carefully, and it would be reading somebody's inbox rather than
 * the estate. No requirement asks for it, so it does not exist.
 *
 * ── No `notifications.*` permission was invented ───────────────────────────
 *
 * The Client's §8.4 permission matrix has no notification module, and OD-4 set
 * the precedent during WP-2.6b that inventing a permission to close a boundary
 * changes that matrix. Ownership is the whole rule here, so a permission would
 * add a second condition that is either always true or actively wrong.
 *
 * ── The list rule and the single-record rule are the same rule ─────────────
 *
 * `NotificationController::index()` scopes on `user_id`; `view()` below asks the
 * same question of one row. That equivalence is what makes a notification absent
 * from someone's list equally unreachable by pasting its uuid — the DD-40
 * property, restated for the simplest case there is.
 */
class NotificationPolicy
{
    public function view(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    /** Marking read or unread is the only mutation a notification has. */
    public function update(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function delete(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    private function owns(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->getKey();
    }
}
