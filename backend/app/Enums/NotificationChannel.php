<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * Where a notification can reach a user (SRS FR-NOT-002).
 *
 * The values are mirrored by `notification_preferences_channel_check`, so this
 * enum and that constraint must widen together — the migration that adds a case
 * builds the CHECK from {@see values()} for exactly that reason.
 *
 * ── `Digest` is a preference, not a dispatch route ─RULE
 *
 * `InApp` and `Email` answer "where does *this* notification go, now".
 * `Digest` does not: a digest **aggregates rows that already exist** rather than
 * delivering one (SRS FR-NOT-008, SDD DD-63). It is a channel a user can switch
 * on in the preference matrix, and one that `ProjectNotification::via()`
 * deliberately never returns — the daily digest command reads the preference
 * later instead.
 *
 * Adding a case here without teaching `via()` to skip it would throw
 * `UnhandledMatchError` on the next notification to **every** user, because the
 * preference gate is opt-out and would report the new channel as allowed.
 */
enum NotificationChannel: string
{
    use HasValues;

    case InApp = 'in_app';
    case Email = 'email';
    case Digest = 'digest';

    /**
     * The Laravel notification channel this maps to, or `null` when the channel
     * does not deliver at dispatch time.
     *
     * One exhaustive match, in the enum rather than in `via()`, so that adding a
     * case is a compile-time question here instead of a runtime failure there.
     * `null` is not "unsupported" \u2014 it is the honest answer for a channel whose
     * delivery happens somewhere else entirely.
     */
    public function driver(): ?string
    {
        return match ($this) {
            // Our own driver, registered over the framework's channel name.
            self::InApp => 'database',
            self::Email => 'mail',
            // Read later by the daily digest command, never dispatched to.
            self::Digest => null,
        };
    }

    /** Does this channel deliver at dispatch time, or is it read later? */
    public function isDispatchable(): bool
    {
        return $this->driver() !== null;
    }
}
