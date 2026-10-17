<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear `resolved_at` on tickets that were sent back to work (SRS FR-TKT-006).
 *
 * **What went wrong.** `TicketLifecycle` stamped `resolved_at` on a move to
 * `resolved` but cleared it only on a move to `open`. A `resolved → in-progress`
 * move — legal for an administrator or the assigned technician — therefore left
 * the stamp on a ticket that was being worked again. Everything keyed on
 * `resolved_at` then disagreed with the ticket's own status: the teacher
 * dashboard's "Awaiting your confirmation" and "Resolved (30 days)" counted it
 * while the status-based lists they link to did not, and the SLA sweep skipped
 * it as finished. The lifecycle no longer does this; this migration repairs the
 * rows it already left behind.
 *
 * **The rule it applies** is the lifecycle's own invariant: a ticket carries
 * `resolved_at` only while it is `resolved` or `closed`. Any other status with a
 * stamp is a leftover.
 *
 * **What it touches.**
 *  - Only `tickets.resolved_at`, set to NULL, only where the status is neither
 *    `resolved` nor `closed`. `closed_at`, `reopened_at` and every other column
 *    are left alone.
 *  - Soft-deleted tickets are included (the query builder applies no scope), so
 *    an archived ticket is not left disagreeing with itself.
 *  - `updated_at` is deliberately **not** bumped: this is a repair, not an edit,
 *    and moving a row's `updated_at` would reorder every list sorted by it.
 *
 * Idempotent: a second run finds nothing to clear.
 *
 * **Effect to expect.** Clearing the stamp makes a re-worked ticket SLA-live
 * again (`DetectSlaBreaches`, `SlaCalculator`, `TicketMetrics::slaPosture()` all
 * require `resolved_at` to be null). Its deadline is not reset, so one that has
 * already passed is reported as breached, and the hourly sweep announces each
 * such crossing once.
 *
 * `down()` cannot restore what it cleared — the original timestamps are gone —
 * so it does nothing. The pre-deploy snapshot is the way back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settled = DB::table('ticket_statuses')
            ->whereIn('slug', ['resolved', 'closed'])
            ->pluck('id');

        DB::table('tickets')
            ->whereNotNull('resolved_at')
            ->whereNotIn('current_status_id', $settled)
            ->update(['resolved_at' => null]);
    }

    public function down(): void
    {
        // Irreversible by design — see the class docblock.
    }
};
