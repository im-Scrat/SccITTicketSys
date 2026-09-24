<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.7 — Maintenance: indexes for the three queries the module actually
 * runs (SRS NFR-PRF-002).
 *
 * The baseline indexed each foreign key on its own, which is right for joins and
 * wrong for these three access patterns:
 *
 *  - **A technician's queue** filters `technician_id` *and* `status` together;
 *    with two single-column indexes Postgres picks one and filters the rest in
 *    memory. `(technician_id, status)` serves the queue, the history list and
 *    `MaintenanceVisibility::scope()` from one index.
 *
 *  - **Row scope** (FR-MNT-011) is "assigned to me **or** created by me", and
 *    `created_by` was never indexed at all — the `technician_id` half of that
 *    OR was fast and the `created_by` half was a sequential scan.
 *
 *  - **The preventive sweep** (FR-MNT-007) asks for open records with a
 *    `scheduled_for` in a window. `(status, scheduled_for)` answers it in one
 *    range scan instead of filtering every open record by date.
 *
 * `(maintenance_record_id, is_completed)` does the same for the completion gate,
 * which asks "does this record still have an incomplete required item" on every
 * attempt to finish a job.
 *
 * Purely additive: no existing index is dropped, and nothing about the tables
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->index(['technician_id', 'status'], 'maintenance_records_technician_status_index');
            $table->index('created_by', 'maintenance_records_created_by_index');
            $table->index(['status', 'scheduled_for'], 'maintenance_records_status_scheduled_index');
        });

        Schema::table('maintenance_checklists', function (Blueprint $table): void {
            $table->index(['maintenance_record_id', 'is_completed'], 'maintenance_checklists_record_completed_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropIndex('maintenance_records_technician_status_index');
            $table->dropIndex('maintenance_records_created_by_index');
            $table->dropIndex('maintenance_records_status_scheduled_index');
        });

        Schema::table('maintenance_checklists', function (Blueprint $table): void {
            $table->dropIndex('maintenance_checklists_record_completed_index');
        });
    }
};
