<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.6 — Ticket Management: record *who* edited or removed a comment.
 *
 * Additive and narrowly scoped. `ticket_comments` already carries `is_edited`,
 * `edited_at` and a soft-delete column, so the *fact* of an edit or removal is
 * recorded — but not the actor. An Administrator moderating another user's
 * comment is exactly the case where that matters (FR-AUD-003), and reading it
 * back out of `activity_logs` alone would leave the row itself unable to explain
 * its own state.
 *
 * Both columns are nullable, so nothing needs backfilling: existing rows were
 * edited or deleted by their author under the old rules, and a null reads
 * honestly as "not recorded" rather than guessing.
 *
 * The comment system is otherwise untouched — no change to threading, the
 * `is_internal` flag, the soft delete, or the counter-cache trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table): void {
            $table->unsignedBigInteger('edited_by')->nullable()->after('edited_at');
            $table->unsignedBigInteger('deleted_by')->nullable()->after('edited_by');

            $table->index('edited_by');
            $table->index('deleted_by');

            // SET NULL, never cascade: archiving a moderator must not delete the
            // comments they moderated, which is the same stance every other
            // actor FK in this schema takes.
            $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table): void {
            $table->dropForeign(['edited_by']);
            $table->dropForeign(['deleted_by']);
            $table->dropIndex(['edited_by']);
            $table->dropIndex(['deleted_by']);
            $table->dropColumn(['edited_by', 'deleted_by']);
        });
    }
};
