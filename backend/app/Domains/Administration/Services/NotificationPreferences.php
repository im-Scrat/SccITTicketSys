<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * **The per-user, per-channel, per-type preference gate** (SRS FR-NOT-002).
 *
 * The baselined `notification_preferences` table is a matrix — `UNIQUE
 * (user_id, channel, notification_type)`, two channels × nine types, so at most
 * eighteen rows per user — and `is_enabled` defaults to **true**.
 *
 * ── An absent row means enabled, and getting this backwards is silent ──────
 *
 * That default makes the model **opt-out**: a user receives everything until
 * they turn something off. So a missing row is not "unknown", it is
 * "enabled" — and a gate that read it as "disabled" would suppress every
 * notification for every user who has never opened the preferences screen,
 * which is all of them on the day this ships. Nothing would error; the system
 * would simply go quiet. That is the single most likely way this layer could
 * fail unnoticed, which is why the default lives here in one method rather than
 * being re-decided at each call site.
 *
 * ── Reads are cached per request ───────────────────────────────────────────
 *
 * One dispatch asks this question once per recipient per channel — up to two
 * queries per person, multiplied by every recipient of a role audience. The
 * whole matrix for one user is at most eighteen small rows, so it is fetched
 * once and answered from memory thereafter. The cache is per-instance and the
 * service is not a singleton across requests, so a preference change is visible
 * on the next request rather than needing invalidation.
 */
class NotificationPreferences
{
    /** @var array<int, Collection<string, bool>> keyed by user id */
    private array $cache = [];

    /**
     * May this notification type reach this user on this channel?
     *
     * Callers that must ignore preferences do not call this — they declare a
     * forced channel on the notification itself, which is auditable in one
     * place. See ProjectNotification::forcedChannels().
     */
    public function allows(User $user, NotificationChannel $channel, NotificationType $type): bool
    {
        return $this->matrixFor($user)->get($this->key($channel, $type), true);
    }

    /**
     * The user's complete preference matrix, absent rows filled in as enabled.
     *
     * This is the shape the WP-2.7b preferences screen reads: every valid
     * combination present and answered, so the client never has to know that
     * "no row" and "enabled" are the same thing.
     *
     * @return list<array{channel: string, notification_type: string, is_enabled: bool}>
     */
    public function matrix(User $user): array
    {
        $stored = $this->matrixFor($user);
        $out = [];

        foreach (NotificationChannel::cases() as $channel) {
            foreach (NotificationType::cases() as $type) {
                $out[] = [
                    'channel' => $channel->value,
                    'notification_type' => $type->value,
                    'is_enabled' => $stored->get($this->key($channel, $type), true),
                ];
            }
        }

        return $out;
    }

    /**
     * Record a user's choice for one cell of the matrix.
     *
     * `updateOrCreate` against the unique key, so setting the same preference
     * twice is one row and a concurrent double-submit cannot produce two.
     */
    public function set(User $user, NotificationChannel $channel, NotificationType $type, bool $enabled): void
    {
        NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'channel' => $channel->value,
                'notification_type' => $type->value,
            ],
            ['is_enabled' => $enabled],
        );

        unset($this->cache[$user->getKey()]);
    }

    /** Drop the memoised matrix for a user (tests, and after a bulk update). */
    public function forget(User $user): void
    {
        unset($this->cache[$user->getKey()]);
    }

    /**
     * @return Collection<string, bool>
     */
    private function matrixFor(User $user): Collection
    {
        $id = (int) $user->getKey();

        return $this->cache[$id] ??= NotificationPreference::query()
            ->where('user_id', $id)
            ->get(['channel', 'notification_type', 'is_enabled'])
            ->mapWithKeys(fn (NotificationPreference $row): array => [
                $this->key($row->channel, $row->notification_type) => $row->is_enabled,
            ]);
    }

    private function key(NotificationChannel $channel, NotificationType $type): string
    {
        return $channel->value.'|'.$type->value;
    }
}
