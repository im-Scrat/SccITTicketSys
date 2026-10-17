<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.9 — report-a-problem form (SDD DD-79): a ticket may name a piece of
 * equipment that is not a PC — a printer, a projector, a monitor — through
 * `tickets.asset_id`.
 *
 * **What it touches.**
 *  - Adds `asset_id bigint NULL`, its index `tickets_asset_id_index`, and
 *    `tickets_asset_id_foreign` → `assets.id` **ON DELETE SET NULL**, exactly
 *    as `pc_unit_id` behaves: purging an asset must never take a fault report
 *    with it. (Assets are archived, not deleted, in normal operation.)
 *  - Adds `tickets_equipment_target_check`:
 *    `num_nonnulls(pc_unit_id, asset_id) <= 1` — a ticket names at most one
 *    item. The same shape as `qr_codes_target_check`.
 *
 * **Why `<= 1` and not `= 1`.** A ticket about a room, or about nothing
 * physical at all, is legal and common.
 *
 * **Why this CHECK may name two SET NULL columns.** The rule elsewhere in this
 * schema (see `2026_10_02_120000`) is that a CHECK naming a SET NULL column can
 * block the cascade. That happens only when nulling the column can make the
 * expression false. Here a cascade can only *remove* a non-null, which can only
 * lower `num_nonnulls`, so it can never turn `<= 1` false.
 *
 * Every existing row has `asset_id` NULL, so the constraint validates at once.
 *
 * `down()` drops the constraint, the foreign key, the index, then the column
 * (and every asset link with it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->index('asset_id', 'tickets_asset_id_index');
            $table->foreign('asset_id', 'tickets_asset_id_foreign')
                ->references('id')->on('assets')->nullOnDelete();
        });

        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_equipment_target_check CHECK (num_nonnulls(pc_unit_id, asset_id) <= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_equipment_target_check');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign('tickets_asset_id_foreign');
            $table->dropIndex('tickets_asset_id_index');
            $table->dropColumn('asset_id');
        });
    }
};
