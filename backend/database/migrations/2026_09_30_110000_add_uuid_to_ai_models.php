<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-O — a public identifier for registry models (SRS FR-AI-014/015; SDD DD-74).
 *
 * The administrator AI settings screen chooses the active chat and embedding
 * model. The baselined `ai_models` table has only an auto-increment id, and
 * public record identifiers are UUID route keys, never auto-increment ids
 * (CLAUDE.md §1) — so the API addresses a registry model by `uuid`. Existing
 * rows receive one from the column default; nothing else about the table moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'));
        });

        DB::statement('CREATE UNIQUE INDEX ai_models_uuid_unique ON ai_models (uuid)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_models_uuid_unique');

        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
