<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.9 — report-a-problem form (SDD DD-77): choosing an "Other" category
 * asks the reporter to describe the kind of problem in a few words.
 *
 * **Why a column and not a slug list in code.** Which categories need a
 * description is a property of the category, so it lives on the row: the
 * options endpoint hands it to the form, and both FormRequests read it from
 * the database. Code never names "the other slugs", so a category added later
 * opts in with data, not with a deploy.
 *
 * **What it touches.**
 *  - Adds `ticket_categories.requires_detail boolean NOT NULL DEFAULT false`,
 *    guarded with `hasColumn` so `up()` can be re-run.
 *  - Flags the three catch-all categories in {@see DETAIL_SLUGS}, whichever of
 *    them exist. The flag is set unconditionally for those slugs: the column is
 *    new, so there is no administrator choice to overwrite, and there is no
 *    category administration route that could have made one.
 *
 * The list is frozen here on purpose — a migration must keep meaning what it
 * meant when it ran. `TicketLookupSeeder` carries the same list, and on a fresh
 * database it is the seeder that flags legacy `other`: this migration runs
 * before any seeding, when only the two rows `2026_10_13_100000` inserts exist.
 *
 * `down()` drops the column (and with it every flag).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const DETAIL_SLUGS = ['other-common', 'other-advanced', 'other'];

    public function up(): void
    {
        if (! Schema::hasColumn('ticket_categories', 'requires_detail')) {
            Schema::table('ticket_categories', function (Blueprint $table): void {
                $table->boolean('requires_detail')->default(false);
            });
        }

        DB::table('ticket_categories')
            ->whereIn('slug', self::DETAIL_SLUGS)
            ->where('requires_detail', false)
            ->update(['requires_detail' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_categories', 'requires_detail')) {
            Schema::table('ticket_categories', function (Blueprint $table): void {
                $table->dropColumn('requires_detail');
            });
        }
    }
};
