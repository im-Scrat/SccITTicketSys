<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.9 — report-a-problem form (SDD DD-77): `tickets.category_detail`,
 * the reporter's few words describing the kind of problem when the category is
 * an "Other" one (`ticket_categories.requires_detail`).
 *
 * **What it touches.**
 *  - Adds `category_detail varchar(120) NULL`. NULL means "no description":
 *    every existing ticket, and every ticket under a category that does not ask
 *    for one. The 3–120 character rule lives in the FormRequests, as every
 *    length in this schema does.
 *  - Adds `tickets_category_detail_check`: a description, when present, is not
 *    blank.
 *
 * **`!~ '^[[:space:]]*$'` and not `btrim(x) <> ''`** — the same test, for the
 * same reason, as `2026_08_29_100010_create_work_support_requests`: bare
 * `btrim` strips spaces only, so `"\t\n"` would slip past the database while
 * the application (TrimStrings, `Str::trim`) refuses it. The POSIX class
 * covers the whole whitespace class in one expression, so the backstop refuses
 * the identical set of values the layers above it do.
 *
 * Deliberately **not** added to `search_vector`: rebuilding that generated
 * column rewrites the whole table, and the staff list already shows the
 * description next to the category.
 *
 * `down()` drops the constraint, then the column (and its data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('category_detail', 120)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tickets
            ADD CONSTRAINT tickets_category_detail_check
            CHECK (category_detail IS NULL OR category_detail !~ '^[[:space:]]*$')
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_category_detail_check');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('category_detail');
        });
    }
};
