<?php

declare(strict_types=1);

use App\Enums\TicketPrioritySource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.9 — location-aware priority (owner decision D-B; the SRS's own
 * intent of "advisory AI plus administrator-configured priority locations").
 *
 * - `rooms.priority_floor_id` — the lowest priority any ticket filed in this
 *   room may have (a server room, an exam lab). Deterministic and
 *   administrator-owned: it is applied by code at creation, never by the AI.
 * - `tickets.priority_source` — why the ticket has its priority
 *   ({@see TicketPrioritySource}). The AI checks it so it can never override a
 *   human's explicit choice. NULL = recorded before this column existed.
 * - `ai_analysis_logs.recommended_priority_id` — what the AI recommended,
 *   kept whether or not it was applied, so an administrator can review it.
 *
 * Both foreign keys clear on delete (a retired priority level must not take
 * rooms or analysis history with it) and deliberately carry **no** CHECK: a
 * CHECK that names a SET NULL column blocks the cascade. The CHECK on
 * `priority_source` is the varchar + CHECK convention this schema uses for
 * every enum-backed column. Purely additive; no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->unsignedBigInteger('priority_floor_id')->nullable();
            $table->index('priority_floor_id', 'rooms_priority_floor_id_index');
            $table->foreign('priority_floor_id', 'rooms_priority_floor_id_foreign')
                ->references('id')->on('ticket_priorities')->nullOnDelete();
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('priority_source', 16)->nullable();
        });

        $sources = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            TicketPrioritySource::values(),
        ));
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_source_check CHECK (priority_source IS NULL OR priority_source IN ({$sources}))");

        Schema::table('ai_analysis_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('recommended_priority_id')->nullable();
            $table->index('recommended_priority_id', 'ai_analysis_logs_recommended_priority_id_index');
            $table->foreign('recommended_priority_id', 'ai_analysis_logs_recommended_priority_id_foreign')
                ->references('id')->on('ticket_priorities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_analysis_logs', function (Blueprint $table): void {
            $table->dropForeign('ai_analysis_logs_recommended_priority_id_foreign');
            $table->dropIndex('ai_analysis_logs_recommended_priority_id_index');
            $table->dropColumn('recommended_priority_id');
        });

        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_priority_source_check');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('priority_source');
        });

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropForeign('rooms_priority_floor_id_foreign');
            $table->dropIndex('rooms_priority_floor_id_index');
            $table->dropColumn('priority_floor_id');
        });
    }
};
