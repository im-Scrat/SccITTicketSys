<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.7 — Maintenance: name the serialized asset that came *out*
 * (SRS FR-MNT-006, DR-022; SDD DD-56).
 *
 * `hardware_replacements` was baselined able to say which **generic component**
 * was swapped (`old_component_id`/`new_component_id` → `hardware_components`)
 * and which **serialized asset** went in (`new_asset_id` → `assets`) — but not
 * which serialized asset came out. `pc_component_installations` is keyed on
 * `asset_id`, so AC-MNT-006's *"the replaced asset is no longer marked installed
 * in that PC"* has nothing to close without this column.
 *
 * The alternative — inferring the outgoing asset from the open installations
 * whose component type matches — guesses, and guesses wrong the first time a
 * machine holds two of the same part. A replacement action that closes the wrong
 * installation is worse than one that closes none.
 *
 * **Nullable**, because a replaced part is frequently not a registered
 * serialized asset at all: a fan, a thermal pad, a cable. Those replacements are
 * still worth recording, and forcing a serialized reference would either block
 * them or invite fake asset rows.
 *
 * `ON DELETE SET NULL` matches every other asset reference in this schema
 * (`maintenance_records.asset_id`, `hardware_replacements.new_asset_id`):
 * purging an asset must not delete the maintenance history that mentions it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_replacements', function (Blueprint $table): void {
            $table->unsignedBigInteger('old_asset_id')->nullable()->after('old_component_id');

            $table->index('old_asset_id');
            $table->foreign('old_asset_id')->references('id')->on('assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hardware_replacements', function (Blueprint $table): void {
            $table->dropForeign(['old_asset_id']);
            $table->dropIndex(['old_asset_id']);
            $table->dropColumn('old_asset_id');
        });
    }
};
