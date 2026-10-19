<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.6 — Ticket Management: index the SLA deadline columns.
 *
 * Additive; no column is added, renamed or removed.
 *
 * `TicketMetrics::slaPosture()` already filters `response_due_at` and
 * `resolution_due_at` on every dashboard load, and Phase 2.6 adds the technician
 * queue ordered by the same columns — but the baselined schema indexes neither.
 * Both queries also exclude soft-deleted rows, so partial indexes are both
 * smaller and a better match than plain btrees.
 */
return new class extends Migration
{
    /** index name => column */
    private const INDEXES = [
        'tickets_resolution_due_at_live_index' => 'resolution_due_at',
        'tickets_response_due_at_live_index' => 'response_due_at',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $column) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$name} ON tickets ({$column}) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
