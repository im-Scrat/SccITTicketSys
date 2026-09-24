<?php

use App\Enums\NotificationChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.7e — the daily digest (SRS FR-NOT-008, SDD DD-63).
 *
 * Two changes that are meaningless apart, so they migrate together: the channel
 * a user opts in with, and the table that stops them being told twice.
 *
 * ── 1. `digest` joins the channel domain ───────────────────────────────────
 *
 * `NotificationChannel` is the single source of truth and the baselined
 * administration migration builds this CHECK from `::values()`, so a fresh
 * `migrate` already yields the three-value constraint. This migration reconciles
 * databases created before WP-2.7e (two values). It is the same technique
 * DD-18 used for the `rejected` user status and DD-32 for the asset-status
 * domain — which is why the PHP-enum-mirrored CHECK design was chosen.
 *
 * Widening is safe with rows present: the new domain is a strict superset, so
 * every value that was legal before stays legal and no data migration is
 * required. `notifications` and `notifications_type_check` are untouched —
 * a digest aggregates unread rows, it does not create one.
 *
 * ── 2. `notification_digests` — the exactly-once record ────────────────────
 *
 * DD-60's `dedupe_key` cannot serve here: it lives on `notifications`, and the
 * digest writes no notification row. Without its own record there is nowhere to
 * say "this user has already been sent 2026-09-07's digest", and
 * `queue:work --tries=3` guarantees the question gets asked more than once.
 *
 * The unique index is the authoritative guarantee, not the scheduler's Redis
 * lock: `withoutOverlapping()` prevents two runs *colliding*, which is a
 * different thing from preventing a second run *succeeding*.
 *
 * `digest_date` is the school-local calendar day being **summarised**, not the
 * day the mail goes out — the 07:00 Asia/Manila run on the 8th carries
 * `digest_date = 2026-09-07`. Keying on the delivery day would make a re-run
 * near midnight ambiguous about which day it had already covered.
 *
 * ── down() would fail if it simply re-narrowed the CHECK ───────────────────
 *
 * Restoring the two-value domain re-validates the table, and any `digest` row
 * violates it. The rows are therefore deleted first. **That discards real user
 * preferences** — unavoidable, since a `digest` preference cannot exist under a
 * domain that has no `digest`, and stated here so a rollback is an informed
 * choice rather than a surprise.
 */
return new class extends Migration
{
    /** The pre-WP-2.7e domain, restored by down(). */
    private const ORIGINAL = ['in_app', 'email'];

    public function up(): void
    {
        $this->applyChannelDomain(NotificationChannel::values());

        Schema::create('notification_digests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');

            // The school-local calendar day summarised. A `date`, not a
            // timestamp: the window's timezone is resolved when the run computes
            // its boundaries, and storing an instant here would invite two
            // different answers to "which day was this?".
            $table->date('digest_date');

            /*
             * When the send was **committed**, not when mail was confirmed
             * delivered. WP-2.7e is deliberately at-most-once: the row is
             * written before SMTP is contacted, so a crash mid-send loses that
             * day's digest for that user rather than risking a duplicate.
             * Nullable so a future policy could claim first and confirm after
             * without a second migration.
             */
            $table->timestampTz('sent_at')->nullable();

            $table->timestampsTz();

            // The guarantee. Everything else in the digest path is convenience.
            $table->unique(['user_id', 'digest_date'], 'notification_digests_unique');
        });

        // Cascade, matching `notifications` and `notification_preferences`: the
        // record exists only to describe a user, and outlives no user.
        Schema::table('notification_digests', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digests');

        // A `digest` preference cannot survive the narrower domain. See the
        // class note: this deletes user preferences.
        DB::table('notification_preferences')
            ->where('channel', NotificationChannel::Digest->value)
            ->delete();

        $this->applyChannelDomain(self::ORIGINAL);
    }

    /**
     * Rebuild the channel CHECK around the given domain.
     *
     * Postgres cannot alter a CHECK in place, so it is dropped and re-added.
     * `IF EXISTS` keeps `up()` safe to re-run against a database that already
     * has the widened constraint.
     *
     * @param  list<string>  $values
     */
    private function applyChannelDomain(array $values): void
    {
        $allowed = "'".implode("','", $values)."'";

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_channel_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_channel_check CHECK (channel IN ({$allowed}))");
    }
};
