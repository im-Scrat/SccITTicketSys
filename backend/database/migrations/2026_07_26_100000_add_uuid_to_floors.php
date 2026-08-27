<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.4 — Location Management: public route key for `floors`.
 *
 * Additive, backward-compatible migration on the baselined `floors` table. No
 * existing column is renamed, retyped, or removed.
 *
 * `buildings` and `rooms` were created with a public `uuid`; `floors` was not,
 * because it was originally treated as an internal child table. Phase 2.4
 * exposes floor CRUD over the API (FR-LOC-002), and numeric primary keys must
 * never appear in URLs or payloads (NFR-SEC-001, AC-G2) — so floors now carry
 * the same `uuid` contract as their siblings:
 *
 *  - `gen_random_uuid()` default (safety net for inserts outside Eloquent),
 *  - unique index (route-model binding target),
 *  - `App\Support\Concerns\HasUuidRouteKey` on the model.
 *
 * Postgres evaluates the volatile default per existing row when the column is
 * added, so pre-existing floors are backfilled with distinct uuids; the explicit
 * backfill below is a belt-and-braces guard for any row that slipped through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('floors', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->default(DB::raw('gen_random_uuid()'));
        });

        DB::statement('UPDATE floors SET uuid = gen_random_uuid() WHERE uuid IS NULL');

        Schema::table('floors', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->default(DB::raw('gen_random_uuid()'))->change();
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
