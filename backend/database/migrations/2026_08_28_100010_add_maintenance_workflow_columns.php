<?php

use App\Enums\PcCondition;
use App\Enums\PcStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.7 — Maintenance: two things the baselined schema cannot express.
 *
 * ── 1. Where the PC's prior state lives (FR-MNT-008) ───────────────────────
 *
 * The requirement is `under_maintenance` on the way in, and the **prior** state
 * on the way out. "Prior" has to be stored somewhere: the lifecycle overwrites
 * `pc_units.status`, so the
 * value it replaced is gone, and a machine that was `offline` before a visit
 * must not come back `online` because the code guessed. Two nullable columns on
 * the maintenance record hold what the record itself displaced, so the restore
 * is a read rather than an inference — and a null is a truthful "this record
 * never moved the PC", which is the case for every asset-only record.
 *
 * They are deliberately on `maintenance_records` and not on `pc_units`: the
 * displaced value belongs to the visit that displaced it. Two concurrent
 * records against one machine (a scheduled preventive visit and an active
 * corrective repair — which the Client approved as legitimate) would otherwise
 * fight over a single column on the PC.
 *
 * CHECK domains mirror the `PcStatus` / `PcCondition` enums exactly, the way
 * every other enum-backed column in this schema does (DD-18).
 *
 * ── 2. Required-item enforcement that survives its template (FR-MNT-004) ───
 *
 * `is_required` lives on `checklist_template_items`, and
 * `maintenance_checklists.checklist_template_item_id` is `ON DELETE SET NULL`.
 * So deleting a template item silently disarms the completion gate on every
 * in-flight record that was instantiated from it — the requirement would hold
 * right up until an administrator tidied the catalogue. Copying the flag onto
 * the instance at instantiation time makes the rule a property of the *work*,
 * not of a row someone else may edit later. `sort_order` comes along for the
 * same reason: the checklist should read in the order it was issued in, even
 * after the template is reordered.
 *
 * Both default to a value that changes no existing behaviour: no checklist rows
 * exist yet, and `is_required = false` is the permissive reading anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->string('pc_status_before')->nullable()->after('maintenance_date');
            $table->string('pc_condition_before')->nullable()->after('pc_status_before');
        });

        DB::statement($this->domainCheck('pc_status_before', PcStatus::values()));
        DB::statement($this->domainCheck('pc_condition_before', PcCondition::values()));

        Schema::table('maintenance_checklists', function (Blueprint $table): void {
            $table->boolean('is_required')->default(false)->after('item_label');
            $table->integer('sort_order')->default(0)->after('is_required');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE maintenance_records DROP CONSTRAINT IF EXISTS maintenance_records_pc_status_before_check');
        DB::statement('ALTER TABLE maintenance_records DROP CONSTRAINT IF EXISTS maintenance_records_pc_condition_before_check');

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropColumn(['pc_status_before', 'pc_condition_before']);
        });

        Schema::table('maintenance_checklists', function (Blueprint $table): void {
            $table->dropColumn(['is_required', 'sort_order']);
        });
    }

    /**
     * @param  list<string>  $values
     */
    private function domainCheck(string $column, array $values): string
    {
        $allowed = "'".implode("','", $values)."'";

        // `x IN (...)` is NULL-safe, so a record that never touched a PC passes.
        return "ALTER TABLE maintenance_records ADD CONSTRAINT maintenance_records_{$column}_check CHECK ({$column} IN ({$allowed}))";
    }
};
