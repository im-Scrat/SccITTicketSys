<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.7 — Maintenance: let repair evidence carry what the attachment trust
 * boundary already records for every other upload (SRS DR-021, SDD DD-53).
 *
 * `repair_images` was baselined with only `disk`, `storage_path` and `caption`.
 * That is not enough to satisfy NFR-SEC-007: there is nowhere to store the
 * server-**detected** MIME type, nowhere to store the SHA-256 checksum, and
 * nothing for the download response to re-check before deciding a
 * `Content-Type` and a disposition. `attachments` (tickets) and
 * `asset_attachments` (assets) have carried all four since their own phases.
 *
 * Adding the columns here is what lets maintenance evidence go through
 * `AttachmentSecurity` — the one trust boundary DD-45 established — instead of
 * growing a third upload path with its own, inevitably weaker, rules. This is
 * the whole point of DD-53: two upload designs means one of them is the weak
 * one, and three would be worse.
 *
 * All four are nullable, so nothing is backfilled. A pre-existing row reads
 * honestly as "not recorded", and `AttachmentSecurity::stream()` already
 * degrades an unknown stored type to `application/octet-stream` rather than
 * trusting it — so old rows are safe by the same mechanism that protects new
 * ones, with no data migration.
 *
 * `repair_images` keeps its `created_at`-only shape (it has no `updated_at`);
 * nothing about the existing columns, the `image_type` CHECK or the cascade from
 * `maintenance_records` is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_images', function (Blueprint $table): void {
            $table->string('original_filename')->nullable()->after('storage_path');
            $table->string('mime_type')->nullable()->after('original_filename');
            $table->bigInteger('file_size')->nullable()->after('mime_type');
            $table->string('checksum')->nullable()->after('file_size');
        });

        // Mirrors `asset_attachments_file_size_check` — a negative byte count is
        // not a missing value, it is a corrupt one.
        DB::statement('ALTER TABLE repair_images ADD CONSTRAINT repair_images_file_size_check CHECK (file_size IS NULL OR file_size >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE repair_images DROP CONSTRAINT IF EXISTS repair_images_file_size_check');

        Schema::table('repair_images', function (Blueprint $table): void {
            $table->dropColumn(['original_filename', 'mime_type', 'file_size', 'checksum']);
        });
    }
};
