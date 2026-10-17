<?php

declare(strict_types=1);

use App\Enums\TicketPrioritySource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.9 — report-a-problem form (SDD DD-75): a reporter may choose the
 * priority of their own fault, so `tickets.priority_source` gains `reporter`.
 *
 * **What it touches.** Only `tickets_priority_source_check`, dropped and
 * re-added with the five-value domain. No row changes on the way up.
 *
 * **Why both lists are frozen here, and never read from the enum.**
 * `2026_10_02_120000_add_location_priority_and_ai_recommendation` built this
 * CHECK from the *live* {@see TicketPrioritySource}, so on a fresh database
 * (every test run, every new install) adding a case silently widens the
 * constraint — while production, which ran that migration long ago, stays
 * narrow. A test suite would then accept a value production refuses, and the
 * forgotten migration would surface as a 500 in production instead of a red
 * test. Freezing the list means this migration keeps meaning what it meant
 * when it ran, and a test compares the migrated constraint with the enum.
 *
 * **Never edit this migration to add a value.** It has already run in
 * production, so an edit here is a no-op there. Add a NEW migration with its
 * own frozen lists, in this same shape.
 *
 * **`down()`** first turns every `reporter` row into NULL, because re-adding
 * the narrow CHECK would otherwise fail on them. NULL is the honest fallback:
 * it already means "unknown" (rows older than the column), it is raisable by
 * the AI exactly as `reporter` is, and the original source survives in the
 * ticket's creation audit. `manual` would lock the AI out and attribute the
 * choice to IT staff; `category` would be false. `updated_at` is deliberately
 * not bumped — this is a schema reversal, not an edit, and moving it would
 * reorder every list sorted by it.
 *
 * Idempotent: `DROP … IF EXISTS` then `ADD`.
 */
return new class extends Migration
{
    /** The domain before this migration, restored by `down()`. */
    private const ORIGINAL = ['category', 'location', 'ai', 'manual'];

    /** The domain this migration installs. */
    private const WITH_REPORTER = ['category', 'reporter', 'location', 'ai', 'manual'];

    public function up(): void
    {
        $this->apply(self::WITH_REPORTER);
    }

    public function down(): void
    {
        DB::table('tickets')
            ->where('priority_source', 'reporter')
            ->update(['priority_source' => null]);

        $this->apply(self::ORIGINAL);
    }

    /**
     * @param  list<string>  $values
     */
    private function apply(array $values): void
    {
        $allowed = implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));

        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_priority_source_check');
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_source_check CHECK (priority_source IS NULL OR priority_source IN ({$allowed}))");
    }
};
