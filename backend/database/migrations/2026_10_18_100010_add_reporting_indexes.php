<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.10 — indexes for the dashboard and Overall Reports queries.
 *
 * Additive; no column is added, renamed or removed. Each one serves a range the
 * existing indexes cannot:
 *
 *  - `tickets (created_at)` and `tickets (room_id, created_at)`, partial on
 *    live rows: every report counts tickets *reported* in a range, overall or
 *    per room. The existing `(current_status_id, created_at)` index cannot
 *    serve a range without an equality on its leading column, and the single
 *    `room_id` index cannot apply the range. Partial for the same reason as
 *    the 2026-08-27 SLA indexes: soft-deleted rows are never counted.
 *  - `ticket_status_history (to_status_id, created_at)`: "entered Resolved
 *    within the period" and the other lifecycle figures.
 *  - `maintenance_records (completed_at)`, partial on completed live rows, and
 *    `(pc_unit_id, completed_at)`: repairs completed in a range, overall and
 *    per PC (the Overall Reports repairs section and the preventive rules).
 *  - `hardware_replacements (replaced_at)`: replacements in a range.
 *
 * Plain `CREATE INDEX IF NOT EXISTS`, the project's precedent: the tables are
 * small (production holds 8 tickets on 2026-10-17), so locking is negligible.
 */
return new class extends Migration
{
    /** @var array<string, string> index name => definition */
    private const INDEXES = [
        'tickets_created_at_live_index' => 'tickets (created_at) WHERE deleted_at IS NULL',
        'tickets_room_created_at_live_index' => 'tickets (room_id, created_at) WHERE deleted_at IS NULL',
        'ticket_status_history_to_status_created_at_index' => 'ticket_status_history (to_status_id, created_at)',
        'maintenance_records_completed_at_live_index' => 'maintenance_records (completed_at) WHERE completed_at IS NOT NULL AND deleted_at IS NULL',
        'maintenance_records_pc_unit_completed_at_index' => 'maintenance_records (pc_unit_id, completed_at)',
        'hardware_replacements_replaced_at_index' => 'hardware_replacements (replaced_at)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
