<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.9 — `tickets.comment_count` counts **public** comments only.
 *
 * The counter-cache trigger (FR-TKT-010) counted every live comment, internal
 * notes included. Internal notes are staff-only (`canSeeInternal`), but the
 * count is served on the community card ({@see TicketFeedResource}) and the
 * teacher's own ticket — so a teacher could watch "Comments: 3" sit above two
 * visible comments and learn that staff were writing notes they cannot read.
 * The existence of an internal note is itself internal.
 *
 * The cached column therefore now means "comments the community can see".
 * Staff surfaces that need internal notes read them from `ticket_comments`.
 *
 * The rewritten function is state-based rather than event-based: a row "counts"
 * when it is live AND public, and every INSERT/UPDATE/DELETE adjusts by the
 * difference between before and after. That covers soft delete, restore, and
 * an `is_internal` flip in one rule instead of one branch per case.
 *
 * Existing values are recounted from the table, touching only the rows whose
 * cached value is actually wrong. `down()` restores the original function and
 * recounts under the original meaning, so a rollback leaves no drift either.
 *
 * Per FR-TKT-010 this stays in PostgreSQL: PHP never writes `comment_count`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_ticket_comments_counter() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                was_counted boolean := false;
                is_counted  boolean := false;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    was_counted := OLD.deleted_at IS NULL AND NOT OLD.is_internal;
                END IF;
                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    is_counted := NEW.deleted_at IS NULL AND NOT NEW.is_internal;
                END IF;

                IF is_counted AND NOT was_counted THEN
                    UPDATE tickets SET comment_count = comment_count + 1 WHERE id = NEW.ticket_id;
                ELSIF was_counted AND NOT is_counted THEN
                    UPDATE tickets SET comment_count = GREATEST(comment_count - 1, 0) WHERE id = OLD.ticket_id;
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);

        $this->recount(publicOnly: true);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_ticket_comments_counter() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.deleted_at IS NULL THEN
                        UPDATE tickets SET comment_count = comment_count + 1 WHERE id = NEW.ticket_id;
                    END IF;
                ELSIF TG_OP = 'DELETE' THEN
                    IF OLD.deleted_at IS NULL THEN
                        UPDATE tickets SET comment_count = GREATEST(comment_count - 1, 0) WHERE id = OLD.ticket_id;
                    END IF;
                ELSIF TG_OP = 'UPDATE' THEN
                    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                        UPDATE tickets SET comment_count = GREATEST(comment_count - 1, 0) WHERE id = NEW.ticket_id;
                    ELSIF OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL THEN
                        UPDATE tickets SET comment_count = comment_count + 1 WHERE id = NEW.ticket_id;
                    END IF;
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);

        $this->recount(publicOnly: false);
    }

    /** Bring every cached count in line with the table, under the given meaning. */
    private function recount(bool $publicOnly): void
    {
        $filter = $publicOnly ? 'AND c.is_internal = false' : '';

        DB::statement(<<<SQL
            UPDATE tickets t
               SET comment_count = actual.n
              FROM (
                    SELECT t2.id,
                           (SELECT count(*) FROM ticket_comments c
                             WHERE c.ticket_id = t2.id AND c.deleted_at IS NULL {$filter}) AS n
                      FROM tickets t2
                   ) AS actual
             WHERE actual.id = t.id
               AND t.comment_count IS DISTINCT FROM actual.n
        SQL);
    }
};
