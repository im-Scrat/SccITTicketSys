<?php

use App\Enums\RoomType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locations & Interactive Floor Plan:
 * buildings -> floors -> rooms -> room_layouts -> floor_plan_positions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('created_by');
            $table->index('updated_by');
        });

        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->integer('floor_number');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['building_id', 'floor_number']);
            $table->index('created_by');
            $table->index('updated_by');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('floor_id');
            $table->enum('room_type', RoomType::values())->default(RoomType::Laboratory->value);
            $table->string('name');
            $table->string('code')->unique();
            $table->string('room_number')->nullable();
            $table->integer('capacity')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('floor_id');
            $table->index('room_type');
            $table->index('created_by');
            $table->index('updated_by');
        });
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_check CHECK (capacity IS NULL OR capacity >= 0)');

        Schema::create('room_layouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->integer('version')->default(1);
            $table->integer('width');
            $table->integer('height');
            $table->integer('grid_size')->default(20);
            $table->string('background_image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();

            $table->unique(['room_id', 'version']);
            $table->index('created_by');
            $table->index('updated_by');
        });
        DB::statement('ALTER TABLE room_layouts ADD CONSTRAINT room_layouts_dimensions_check CHECK (width > 0 AND height > 0 AND grid_size > 0)');

        Schema::create('floor_plan_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_layout_id');
            $table->unsignedBigInteger('pc_unit_id');
            $table->decimal('pos_x', 10, 2);
            $table->decimal('pos_y', 10, 2);
            $table->decimal('rotation', 5, 2)->default(0);
            $table->integer('z_index')->default(0);
            $table->timestampsTz();

            $table->unique(['room_layout_id', 'pc_unit_id']);
            $table->index('pc_unit_id');
        });
        DB::statement('ALTER TABLE floor_plan_positions ADD CONSTRAINT floor_plan_positions_coords_check CHECK (pos_x >= 0 AND pos_y >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plan_positions');
        Schema::dropIfExists('room_layouts');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
    }
};
