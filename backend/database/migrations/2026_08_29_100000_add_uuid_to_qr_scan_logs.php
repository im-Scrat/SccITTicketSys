<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.6b — give a scan a public identity, so proof of work can be idempotent
 * *on the scan* (SRS FR-MNT-012; SDD DD-50).
 *
 * ── Why the table needs a uuid ─────────────────────────────────────────────
 *
 * FR-MNT-012 requires that "a resubmission of the same scan shall **update**
 * that record rather than create a second one" — the operation is idempotent on
 * the **scan**, which is the single physical event of a technician holding a
 * phone to a sticker. That is only expressible if the client can name the scan
 * it is submitting against, and `qr_scan_logs` was baselined with nothing but a
 * bigint primary key. Exposing that key would leak row counts and hand out an
 * enumerable handle; every other publicly-addressable table in this schema
 * carries a `uuid` instead (DD-04), so this is the existing convention applied
 * to one more table, not a new idea.
 *
 * The uuid is what closes the idempotency loop: `maintenance_record_id` on this
 * same row is a single nullable FK, so once a scan is bound to a record it
 * cannot be bound to a second one — the guarantee is the column's cardinality,
 * enforced by the database rather than promised by the code.
 *
 * ── Why `user_agent` ───────────────────────────────────────────────────────
 *
 * No FR-QR requires it; `activity_logs` and `login_history` both record it, and
 * a scan log that can say *what* scanned is worth more when the question is
 * "was this an enumeration sweep or a technician's phone?" (FR-QR-013). Nullable
 * and unread by any rule, so nothing depends on it being present.
 *
 * ── Why the composite index ────────────────────────────────────────────────
 *
 * The baseline indexed `qr_code_id` alone and put a BRIN on `scanned_at`. The
 * query WP-2.6b actually runs is "the recent scans for *this* code", which those
 * two serve separately and neither serves together. BRIN is right for the
 * append-only bulk scan of a log and wrong for a selective lookup by one code.
 *
 * Purely additive: no existing column is renamed, retyped or removed, and the
 * three existing CHECK constraints and five foreign keys are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Added nullable so the statement is safe against a table that already
        // holds rows; the backfill below is what makes it total. Both stacks are
        // empty today, but a migration that only works on an empty table is a
        // migration that fails the first time it matters.
        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->text('user_agent')->nullable()->after('ip_address');
        });

        DB::statement('UPDATE qr_scan_logs SET uuid = gen_random_uuid() WHERE uuid IS NULL');

        // NOT NULL *after* the backfill, and a database-side default so a row
        // inserted outside Eloquent still gets an identity — the same safety net
        // every baselined `uuid` column carries.
        DB::statement('ALTER TABLE qr_scan_logs ALTER COLUMN uuid SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE qr_scan_logs ALTER COLUMN uuid SET NOT NULL');

        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->unique('uuid', 'qr_scan_logs_uuid_unique');
            $table->index(['qr_code_id', 'scanned_at'], 'qr_scan_logs_code_scanned_index');
        });
    }

    public function down(): void
    {
        Schema::table('qr_scan_logs', function (Blueprint $table): void {
            $table->dropIndex('qr_scan_logs_code_scanned_index');
            $table->dropUnique('qr_scan_logs_uuid_unique');
            $table->dropColumn(['uuid', 'user_agent']);
        });
    }
};
