<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.10 R5 — AI interpretations of the overall report (SDD DD-95; SRS
 * FR-RPT-011).
 *
 * One row per interpretation asked for. `cache_key` binds it to exactly what
 * it interpreted: the report, the resolved filters, the sha256 of the facts
 * the application computed, the prompt version and the model — a change to
 * any of them is a different interpretation, never a reuse. `evidence` keeps
 * the fact packet that was sent (codes and counts only: no names, ticket text,
 * notes or costs), so the screen can show the facts beside the reading and an
 * auditor can see what the model was given. `findings` holds only the
 * statements that passed validation; `withheld_count` says how many did not.
 *
 * `ai_analysis_logs` is ticket-scoped (its `ticket_id` is NOT NULL), so report
 * interpretations get their own table.
 *
 * CHECK lists are frozen here; a schema test keeps them equal to the enum.
 */
return new class extends Migration
{
    private const STATUSES = ['pending', 'completed', 'insufficient', 'unavailable', 'failed', 'disabled'];

    public function up(): void
    {
        Schema::create('report_narratives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('report_type', 32);
            $table->char('cache_key', 64);
            $table->char('filters_hash', 40);
            $table->char('facts_hash', 64);
            $table->string('prompt_version', 16);
            $table->string('model', 128)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('filters');
            $table->string('status', 16)->default('pending');
            $table->jsonb('evidence');
            $table->jsonb('coverage')->nullable();
            $table->jsonb('findings')->nullable();
            $table->jsonb('limitations')->nullable();
            $table->unsignedSmallInteger('withheld_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();

            $table->unique('cache_key', 'report_narratives_cache_key_unique');
            $table->index(['report_type', 'filters_hash', 'created_at'], 'report_narratives_filters_index');
        });

        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", self::STATUSES));
        DB::statement("ALTER TABLE report_narratives ADD CONSTRAINT report_narratives_status_check CHECK (status IN ({$list}))");
        DB::statement("ALTER TABLE report_narratives ADD CONSTRAINT report_narratives_report_type_check CHECK (report_type IN ('overall'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_narratives');
    }
};
