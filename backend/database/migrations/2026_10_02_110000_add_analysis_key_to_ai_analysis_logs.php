<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.9 — make ticket pre-screening idempotent under concurrency.
 *
 * Analyses now run on several workers at once, and a job can run more than
 * once (a retry after a worker died, a duplicate dispatch). Without a key, each
 * run inserted another `ai_analysis_logs` row and another set of
 * recommendations for the same ticket. `analysis_key` names *which* analysis a
 * row is (`ticket:{id}:created` for the on-creation pre-screening), and the
 * partial unique index makes the database — not a check-then-insert race in
 * PHP — refuse the second one. The same pattern as `notifications.dedupe_key`.
 *
 * Partial (`WHERE analysis_key IS NOT NULL`) so existing rows, and any future
 * on-demand re-analysis that should legitimately add history (FR-AI-001 "or on
 * demand"), stay unconstrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_analysis_logs', function (Blueprint $table) {
            $table->string('analysis_key', 120)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX ai_analysis_logs_analysis_key_unique ON ai_analysis_logs (analysis_key) WHERE analysis_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_analysis_logs_analysis_key_unique');

        Schema::table('ai_analysis_logs', function (Blueprint $table) {
            $table->dropColumn('analysis_key');
        });
    }
};
