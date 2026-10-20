<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Placement confirmation and equipment on the Floorplan (owner request
 * 2026-10-20, SDD DD-115).
 *
 * A custodian confirms, from "My assets", that an asset assigned to them is
 * physically in its room. A confirmed asset then appears on that room's
 * Floorplan, where an administrator arranges it alongside the PCs.
 *
 * **What it touches.**
 *  - `assets.placement_confirmed_at timestamptz NULL` and
 *    `assets.placement_confirmed_by` (FK users, SET NULL). NULL means "not
 *    confirmed": every existing asset, and any asset moved since it was
 *    confirmed (`TransferAsset` clears both). Deliberately **no** CHECK pairing
 *    the two columns — a CHECK naming a SET NULL column would block the FK
 *    cascade when the confirming user is deleted.
 *  - `floor_plan_asset_positions`: where a confirmed asset stands on a layout.
 *    A table of its own rather than a nullable `asset_id` on
 *    `floor_plan_positions`, so every invariant the PC table carries (its
 *    unique key, its NOT NULL `pc_unit_id`, the code that trusts it) stays
 *    exactly as it is. Same coordinate, rotation and precision rules as PCs:
 *    positions are non-negative, rotation is a right angle, and `updated_at`
 *    keeps microseconds (the WP-F lesson).
 *
 * `down()` drops the table, then the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->timestampTz('placement_confirmed_at')->nullable();
            $table->foreignId('placement_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('floor_plan_asset_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_layout_id')->constrained('room_layouts')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->decimal('pos_x', 10, 2);
            $table->decimal('pos_y', 10, 2);
            $table->decimal('rotation', 5, 2)->default(0);
            $table->integer('z_index')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at', 6)->nullable();

            $table->unique(['room_layout_id', 'asset_id']);
            $table->index('asset_id');
        });

        DB::statement('ALTER TABLE floor_plan_asset_positions ADD CONSTRAINT floor_plan_asset_positions_coords_check CHECK (pos_x >= 0 AND pos_y >= 0)');
        DB::statement('ALTER TABLE floor_plan_asset_positions ADD CONSTRAINT floor_plan_asset_positions_rotation_check CHECK (rotation IN (0, 90, 180, 270))');
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plan_asset_positions');

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('placement_confirmed_by');
            $table->dropColumn('placement_confirmed_at');
        });
    }
};
