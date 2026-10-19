<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.5 — Asset Management: images and documents for an asset or PC unit.
 *
 * New table; nothing existing is touched. The baselined `attachments` table
 * cannot be reused — it carries a NOT NULL `ticket_id` and a counter-cache
 * trigger that writes back to `tickets` — and `repair_images` is scoped to a
 * maintenance record. Asset evidence is a third thing, with its own lifetime:
 * it outlives any one ticket.
 *
 * **Exactly one target.** `num_nonnulls(asset_id, pc_unit_id) = 1` mirrors
 * `qr_codes_target_check`, so an attachment can never be orphaned between two
 * owners or belong to both (SDD DD-35). Both FKs cascade: deleting the target
 * for real (a hard delete, not the soft delete the app uses) takes its files'
 * rows with it.
 *
 * Storage itself is a private disk; only `storage_path` is recorded here, and
 * downloads are streamed through an authorized controller — the path is never a
 * public URL (NFR-SEC-007/008).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();

            $table->string('kind')->default('document');
            $table->string('disk')->default('local');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('checksum')->nullable();
            $table->string('caption')->nullable();

            $table->timestampsTz();

            $table->index('asset_id');
            $table->index('pc_unit_id');
            $table->index('uploaded_by');
            $table->index('kind');

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('pc_unit_id')->references('id')->on('pc_units')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE asset_attachments ADD CONSTRAINT asset_attachments_kind_check CHECK (kind IN ('image','document'))");
        DB::statement('ALTER TABLE asset_attachments ADD CONSTRAINT asset_attachments_target_check CHECK (num_nonnulls(asset_id, pc_unit_id) = 1)');
        DB::statement('ALTER TABLE asset_attachments ADD CONSTRAINT asset_attachments_file_size_check CHECK (file_size IS NULL OR file_size >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_attachments');
    }
};
