<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add-asset form (owner request 2026-10-20, SDD DD-114): `assets.category_detail`,
 * the administrator's few words describing the kind of equipment when the
 * category chosen is "Other".
 *
 * **Why a column on the asset, not a new category.** An asset's category is
 * derived through its catalog model's component type, and `other` is one of
 * those types. The description is about *this* asset, so it lives on it; the
 * category vocabulary stays a closed list the dashboards can group by.
 *
 * **What it touches.**
 *  - Adds `category_detail varchar(120) NULL`. NULL means "no description":
 *    every existing asset, and every asset whose category is not "Other". The
 *    3–120 character rule lives in the FormRequests, as every length here does.
 *  - Adds `assets_category_detail_check`: a description, when present, is not
 *    blank — `!~ '^[[:space:]]*$'` rather than `btrim(...) <> ''`, for the reason
 *    `2026_10_16_130000_add_category_detail_to_tickets` gives (btrim strips
 *    spaces only, so a tab-only value would pass the database but not the app).
 *
 * `down()` drops the constraint, then the column (and its data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->string('category_detail', 120)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE assets
            ADD CONSTRAINT assets_category_detail_check
            CHECK (category_detail IS NULL OR category_detail !~ '^[[:space:]]*$')
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_category_detail_check');

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('category_detail');
        });
    }
};
