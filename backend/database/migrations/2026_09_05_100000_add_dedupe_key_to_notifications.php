<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.7a — the one additive column the notification infrastructure needs.
 *
 * Everything else it requires was baselined in `create_administration_tables`:
 * `notifications` (uuid, user_id, type, title, message, data, action_url,
 * read_at), `notification_preferences` (user × channel × type, unique), the
 * cascade foreign keys, and the `notifications_unread` partial index the badge
 * count depends on. No table is created here and none is reshaped.
 *
 * ── Why a column rather than "just don't send twice" ───────────────────────
 *
 * Notifications are delivered by a **queued** job (`--tries=3 --backoff=5`), so
 * "sent exactly once" cannot be a property of the calling code — a worker that
 * writes the row and then dies before acknowledging will run the same job
 * again. Idempotency has to live where the retry can see it, which is the
 * database.
 *
 * The key is scoped **per user**, not globally: one event legitimately produces
 * one row for each of several recipients, and a global unique key would let the
 * first recipient's row silently suppress the second's.
 *
 * NULL is the escape hatch and the reason the index is partial: a notification
 * that is genuinely allowed to repeat carries no key, and Postgres would treat
 * many NULLs as distinct anyway. Making it explicit documents the intent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('dedupe_key')->nullable()->after('data');
        });

        DB::statement(
            'CREATE UNIQUE INDEX notifications_dedupe_unique ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_dedupe_unique');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('dedupe_key');
        });
    }
};
