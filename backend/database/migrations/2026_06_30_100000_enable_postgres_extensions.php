<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable PostgreSQL extensions required by the schema.
 *
 * - citext : case-insensitive text (users.email, supplier/manufacturer emails).
 * - vector : pgvector, for the RAG embedding store (ai_embeddings).
 *
 * `vector` is also enabled by the Docker init script for a fresh data volume;
 * this migration makes it explicit and idempotent for any environment.
 * `gen_random_uuid()` is built into PostgreSQL 13+ (no extension needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // Intentionally not dropping extensions: other objects/databases may
        // depend on them, and dropping is unnecessary for a clean rebuild.
    }
};
