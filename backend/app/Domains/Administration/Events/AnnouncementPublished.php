<?php

declare(strict_types=1);

namespace App\Domains\Administration\Events;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when an administrator publishes an announcement, or deliberately
 * re-notifies its audience (SRS FR-NOT-010, FR-NOT-003 trigger 12; WP-2.7c).
 *
 * ── Publishing is an act, not a state ──────────────────────────────────────
 *
 * This event is raised by the publish and notify-again operations only. It is
 * **not** raised by an ordinary edit, which is the whole of WP-2.7c decision D7: an
 * administrator fixing a typo in a live announcement must not blast the school
 * a second time. When they genuinely want to, they say so, and that is the
 * `$renotified` flag below — carried so the idempotency key can differ and the
 * audit trail can tell a first publication from a deliberate repeat.
 */
class AnnouncementPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Announcement $announcement,
        public readonly User $actor,
        /** True when an administrator explicitly asked to notify again (WP-2.7c D7). */
        public readonly bool $renotified = false,
    ) {}
}
