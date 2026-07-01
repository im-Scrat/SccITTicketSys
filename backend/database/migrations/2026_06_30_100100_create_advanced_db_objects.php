<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Advanced PostgreSQL objects that the schema builder cannot express:
 *   - Full-text search (generated tsvector columns + GIN indexes)
 *   - pgvector HNSW index for RAG similarity search
 *   - BRIN indexes for append-only time-series logs
 *   - Partial / partial-unique indexes (single active row guarantees, unread)
 *   - Triggers (objectively justified only):
 *       * ticket counter caches (votes / comments / attachments)
 *       * audit_logs append-only immutability guard
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Full-text search (generated columns + GIN) ──────────────────
        DB::statement(<<<'SQL'
            ALTER TABLE tickets ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description, ''))
            ) STORED
        SQL);
        DB::statement('CREATE INDEX tickets_search_vector_gin ON tickets USING gin (search_vector)');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_knowledge_articles ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector(
                    'english',
                    coalesce(title, '') || ' ' || coalesce(problem_signature, '') || ' ' || coalesce(verified_solution, '')
                )
            ) STORED
        SQL);
        DB::statement('CREATE INDEX ai_knowledge_articles_search_vector_gin ON ai_knowledge_articles USING gin (search_vector)');

        // ── pgvector similarity (HNSW, cosine) ──────────────────────────
        DB::statement('CREATE INDEX ai_embeddings_embedding_hnsw ON ai_embeddings USING hnsw (embedding vector_cosine_ops)');

        // ── BRIN for append-only time-series logs ───────────────────────
        DB::statement('CREATE INDEX audit_logs_created_at_brin ON audit_logs USING brin (created_at)');
        DB::statement('CREATE INDEX activity_logs_created_at_brin ON activity_logs USING brin (created_at)');
        DB::statement('CREATE INDEX qr_scan_logs_scanned_at_brin ON qr_scan_logs USING brin (scanned_at)');

        // ── Partial (unique) indexes enforcing single-active-row rules ──
        DB::statement('CREATE UNIQUE INDEX room_layouts_one_active_per_room ON room_layouts (room_id) WHERE is_active');
        DB::statement("CREATE UNIQUE INDEX technician_assignments_one_active_per_ticket ON technician_assignments (ticket_id) WHERE status IN ('pending', 'accepted', 'in_progress', 'on_hold')");
        DB::statement('CREATE UNIQUE INDEX pc_component_installations_one_active_per_asset ON pc_component_installations (asset_id) WHERE removal_date IS NULL');
        DB::statement('CREATE UNIQUE INDEX ticket_statuses_single_default ON ticket_statuses (is_default) WHERE is_default');
        DB::statement('CREATE INDEX notifications_unread ON notifications (user_id) WHERE read_at IS NULL');

        // ── Triggers: ticket counter caches ─────────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_ticket_votes_counter() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    UPDATE tickets SET upvote_count = upvote_count + 1 WHERE id = NEW.ticket_id;
                ELSIF TG_OP = 'DELETE' THEN
                    UPDATE tickets SET upvote_count = GREATEST(upvote_count - 1, 0) WHERE id = OLD.ticket_id;
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER ticket_votes_counter AFTER INSERT OR DELETE ON ticket_votes FOR EACH ROW EXECUTE FUNCTION trg_ticket_votes_counter()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_attachments_counter() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    UPDATE tickets SET attachment_count = attachment_count + 1 WHERE id = NEW.ticket_id;
                ELSIF TG_OP = 'DELETE' THEN
                    UPDATE tickets SET attachment_count = GREATEST(attachment_count - 1, 0) WHERE id = OLD.ticket_id;
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER attachments_counter AFTER INSERT OR DELETE ON attachments FOR EACH ROW EXECUTE FUNCTION trg_attachments_counter()');

        // Comments counter is soft-delete aware (deleted_at flips count).
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
        DB::statement('CREATE TRIGGER ticket_comments_counter AFTER INSERT OR UPDATE OR DELETE ON ticket_comments FOR EACH ROW EXECUTE FUNCTION trg_ticket_comments_counter()');

        // ── Trigger: audit_logs append-only immutability ────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_audit_logs_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only; % is not permitted', TG_OP;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION trg_audit_logs_immutable()');
    }

    public function down(): void
    {
        // Triggers + functions
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS trg_audit_logs_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS ticket_comments_counter ON ticket_comments');
        DB::statement('DROP FUNCTION IF EXISTS trg_ticket_comments_counter()');
        DB::statement('DROP TRIGGER IF EXISTS attachments_counter ON attachments');
        DB::statement('DROP FUNCTION IF EXISTS trg_attachments_counter()');
        DB::statement('DROP TRIGGER IF EXISTS ticket_votes_counter ON ticket_votes');
        DB::statement('DROP FUNCTION IF EXISTS trg_ticket_votes_counter()');

        // Partial indexes
        DB::statement('DROP INDEX IF EXISTS notifications_unread');
        DB::statement('DROP INDEX IF EXISTS ticket_statuses_single_default');
        DB::statement('DROP INDEX IF EXISTS pc_component_installations_one_active_per_asset');
        DB::statement('DROP INDEX IF EXISTS technician_assignments_one_active_per_ticket');
        DB::statement('DROP INDEX IF EXISTS room_layouts_one_active_per_room');

        // BRIN
        DB::statement('DROP INDEX IF EXISTS qr_scan_logs_scanned_at_brin');
        DB::statement('DROP INDEX IF EXISTS activity_logs_created_at_brin');
        DB::statement('DROP INDEX IF EXISTS audit_logs_created_at_brin');

        // Vector
        DB::statement('DROP INDEX IF EXISTS ai_embeddings_embedding_hnsw');

        // Full-text
        DB::statement('DROP INDEX IF EXISTS ai_knowledge_articles_search_vector_gin');
        DB::statement('ALTER TABLE ai_knowledge_articles DROP COLUMN IF EXISTS search_vector');
        DB::statement('DROP INDEX IF EXISTS tickets_search_vector_gin');
        DB::statement('ALTER TABLE tickets DROP COLUMN IF EXISTS search_vector');
    }
};
