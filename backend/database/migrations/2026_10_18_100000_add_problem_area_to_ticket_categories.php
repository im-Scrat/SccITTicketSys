<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.10 — the problem area of each ticket category (owner decision D6,
 * SDD DD-87): hardware, software, network or other, so reports can group
 * faults the way the school asks about them.
 *
 * **Why a column and not a slug map in code.** Which area a category belongs
 * to is a property of the category, the same reasoning DD-77 used for
 * `requires_detail`: reports, filters and exports read it from the row, and
 * code never names "the hardware slugs".
 *
 * **What it touches.**
 *  1. Adds `ticket_categories.problem_area varchar(16) NULL` (guarded, so
 *     `up()` can be re-run).
 *  2. Applies the {@see MAPPING} the owner approved on 2026-10-18, to rows
 *     still NULL. Seven categories were ambiguous and the owner chose the
 *     recommended side for each: performance-slowness and blue-screen →
 *     software (first-line fixes are software), power-issue,
 *     audio-video-output and bios-firmware → hardware, browser-web →
 *     software, account-access → other.
 *  3. Puts any remaining NULL (a category added outside the seeded set; none
 *     existed on production on 2026-10-17) in `other`.
 *  4. Makes the column NOT NULL, DEFAULT 'other'. A default rather than none
 *     because earlier migrations insert categories without this column
 *     (`2026_10_13_100000` does, and its tests re-run it against the current
 *     schema) and a migration that has run must keep working unchanged. The
 *     application has no path that creates a category; the seeder sets every
 *     area explicitly, and a test pins the seeded mapping.
 *  5. Adds `ticket_categories_problem_area_check`.
 *
 * The mapping and the CHECK list are frozen here: a migration must keep meaning
 * what it meant when it ran. `TicketLookupSeeder` carries the same mapping for
 * a fresh install, and `ProblemArea` is the PHP domain; a schema test keeps the
 * CHECK and the enum equal. A later change of mind is a new data migration.
 *
 * `down()` drops the constraint and the column.
 */
return new class extends Migration
{
    /** @var array<string, string> slug => problem area, approved 2026-10-18 */
    private const MAPPING = [
        'hardware' => 'hardware',
        'power-issue' => 'hardware',
        'audio-video-output' => 'hardware',
        'peripheral' => 'hardware',
        'bios-firmware' => 'hardware',
        'software' => 'software',
        'performance-slowness' => 'software',
        'os-updates' => 'software',
        'browser-web' => 'software',
        'blue-screen' => 'software',
        'missing-software' => 'software',
        'network' => 'network',
        'account-access' => 'other',
        'other' => 'other',
        'other-common' => 'other',
        'other-advanced' => 'other',
    ];

    /** @var list<string> */
    private const AREAS = ['hardware', 'software', 'network', 'other'];

    public function up(): void
    {
        if (! Schema::hasColumn('ticket_categories', 'problem_area')) {
            Schema::table('ticket_categories', function (Blueprint $table): void {
                $table->string('problem_area', 16)->nullable();
            });
        }

        foreach (self::MAPPING as $slug => $area) {
            DB::table('ticket_categories')
                ->where('slug', $slug)
                ->whereNull('problem_area')
                ->update(['problem_area' => $area, 'updated_at' => now()]);
        }

        DB::table('ticket_categories')
            ->whereNull('problem_area')
            ->update(['problem_area' => 'other', 'updated_at' => now()]);

        DB::statement("ALTER TABLE ticket_categories ALTER COLUMN problem_area SET DEFAULT 'other'");
        DB::statement('ALTER TABLE ticket_categories ALTER COLUMN problem_area SET NOT NULL');

        $list = implode(', ', array_map(static fn (string $area): string => "'{$area}'", self::AREAS));
        DB::statement('ALTER TABLE ticket_categories DROP CONSTRAINT IF EXISTS ticket_categories_problem_area_check');
        DB::statement("ALTER TABLE ticket_categories ADD CONSTRAINT ticket_categories_problem_area_check CHECK (problem_area IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_categories DROP CONSTRAINT IF EXISTS ticket_categories_problem_area_check');

        if (Schema::hasColumn('ticket_categories', 'problem_area')) {
            Schema::table('ticket_categories', function (Blueprint $table): void {
                $table->dropColumn('problem_area');
            });
        }
    }
};
