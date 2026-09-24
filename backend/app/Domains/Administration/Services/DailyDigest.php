<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Enums\NotificationChannel;
use App\Enums\UserStatus;
use App\Models\Notification;
use App\Models\NotificationDigest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * **The daily digest** (SRS FR-NOT-008; SDD DD-63).
 *
 * Everything the digest decides lives here: which day it covers, who is
 * eligible, what they get, and whether they have already been told. The command
 * is the schedule and the mail; this is the rules.
 *
 * ── The digest is read, never written ─────────────────────────────────────
 *
 * A digest **aggregates rows that already exist**. It creates no notification,
 * no badge count and no in-app surface, which is why `digest` is a delivery
 * *channel* rather than a tenth notification type, and why
 * `ProjectNotification::via()` never routes to it.
 *
 * ── "Daily" is the school's day, not the server's ─────────────────────────
 *
 * The application runs in UTC and will keep doing so; only the digest's
 * *business* semantics are school-local. {@see TIMEZONE} is the one place that
 * says which zone that is, so the schedule entry and the window arithmetic
 * cannot drift apart. It is a constant rather than a settings lookup because
 * `routes/console.php` is loaded on every artisan invocation — a query there
 * would run during `migrate`, during `queue:work` boot and in every test, and
 * would fail outright during `migrate:fresh`. A test asserts the constant still
 * matches the seeded `system.timezone`, so the two cannot silently diverge.
 *
 * Asia/Manila is UTC+8 and observes no daylight saving, so a fixed local
 * delivery time is stable year-round and no DST branch exists.
 */
class DailyDigest
{
    /**
     * The school's timezone — the authority for what "daily" means.
     *
     * Mirrors `system_settings['system.timezone']`, which is where the Client
     * declared it. Kept in sync by test, not by hope.
     */
    public const TIMEZONE = 'Asia/Manila';

    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * The window a run at `$runAt` covers: the **previous** school-local
     * calendar day, as a half-open interval.
     *
     * Half-open `[start, end)` is the whole of the boundary rule. A row created
     * at exactly midnight belongs to the day that is starting, not the one that
     * ended, and expressing that as `>= start AND < end` leaves no instant that
     * belongs to both days or to neither.
     *
     * The 07:00 run on the 8th therefore covers the 7th, whose window closed
     * seven hours earlier — the day is settled before it is summarised.
     */
    public function windowFor(CarbonImmutable $runAt): DigestWindow
    {
        $localDay = $runAt->setTimezone(self::TIMEZONE)->subDay()->startOfDay();

        return new DigestWindow(
            date: $localDay->toDateString(),
            start: $localDay,
            end: $localDay->addDay(),
        );
    }

    /**
     * Users who could receive a digest at all.
     *
     * The same eligibility `NotificationAudience` applies to every recipient
     * list: **active accounts only**, soft-deleted excluded by the model. A
     * suspended or pending account cannot sign in to act on a digest, and
     * mailing a rejected applicant a summary of internal activity would be a
     * disclosure. All three roles qualify — the digest gives nobody a capability
     * they did not have.
     *
     * @return Collection<int, User>
     */
    public function candidates(): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * The notifications this user's digest would carry.
     *
     * Three filters, and each is a requirement rather than a preference of the
     * implementation: the rows are **theirs** (no cross-user aggregation ever),
     * **unread** at the moment the run reads them (FR-NOT-008 says "unread
     * items"), and **inside the window**.
     *
     * The per-type gate is then the user's own `digest` preference — the same
     * opt-out matrix every other channel uses, consulted through the same
     * service, so the digest adds no second preference mechanism. A user who
     * has switched off `digest × assignment` gets a digest without assignments,
     * and one who has switched off every type gets nothing at all.
     *
     * Ordered oldest-first: a digest reads as the day in sequence, not as a
     * stack.
     *
     * @return Collection<int, Notification>
     */
    public function itemsFor(User $user, DigestWindow $window): Collection
    {
        $rows = Notification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->where('created_at', '>=', $window->start)
            ->where('created_at', '<', $window->end)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $rows->filter(
            fn (Notification $row): bool => $this->preferences->allows(
                $user,
                NotificationChannel::Digest,
                $row->type,
            ),
        )->values();
    }

    /**
     * Claim this user's digest for this day, committing **before** the send.
     *
     * Returns `false` when somebody already holds the day — the unique index on
     * `(user_id, digest_date)` decides that, not this method, which is the
     * point: two workers racing, a retried job, a manual re-invocation and a
     * redeploy all lose the race in exactly the same way.
     *
     * ── Why the record is written before the mail ─────────────────────────
     *
     * WP-2.7e is **at-most-once** by decision. SMTP cannot enlist in a database
     * transaction, so one of two failures is unavoidable: a crash between
     * sending and recording would duplicate, and a crash between recording and
     * sending would miss. The Client chose to miss. A missed digest is
     * recoverable tomorrow; a duplicate school-wide mailing is not.
     *
     * So `sent_at` means **committed to sending**, never *confirmed delivered* —
     * and no code should read it as proof that mail arrived.
     */
    public function claim(User $user, DigestWindow $window): bool
    {
        $now = CarbonImmutable::now();

        /*
         * `insertOrIgnore` compiles to `INSERT ... ON CONFLICT DO NOTHING`, and
         * the choice is not stylistic. Catching the unique violation instead
         * would work exactly once: PostgreSQL **aborts the surrounding
         * transaction** on a constraint error, so every later statement in the
         * run fails with 25P02 -- one already-sent user would take the rest of
         * the school's digest down with them. Letting the database decline the
         * row without raising keeps the run alive, and the return value is the
         * same answer the exception would have carried.
         *
         * Eloquent is bypassed here, so the timestamps are set by hand.
         */
        $claimed = NotificationDigest::query()->insertOrIgnore([
            'user_id' => $user->getKey(),
            'digest_date' => $window->date,
            // Committed, not confirmed delivered -- see the method note.
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $claimed === 1;
    }
}
