<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-F — `floor_plan_positions.updated_at` needs sub-second precision for the
 * D3 optimistic-concurrency token to actually work.
 *
 * **The discovered problem.** `timestampsTz()` (used everywhere in this
 * schema, not only here) creates Postgres `timestamptz` columns at their
 * default precision — **0, whole seconds only** (confirmed against the live
 * schema: `information_schema.columns.datetime_precision = 0`). D3's design
 * compares `expected_updated_at` against the position's stored `updated_at`
 * to detect a write that happened since the client last read the row. Two
 * accepted writes to the same position inside one wall-clock second — the
 * exact "two administrators, back to back" scenario D3 exists to catch — are
 * therefore byte-identical in `updated_at`, and the stale-write check is a
 * silent no-op for precisely the race it was built to refuse.
 *
 * **This is a precision fix to the one approved mechanism, not a second
 * locking mechanism.** No new column, no version counter, no second
 * migration alongside `expected_updated_at` — `updated_at` remains the sole
 * concurrency token; it is simply made capable of doing what D3 already
 * describes. `PlacePcUnit::assertNotStale()` and `FloorPlanPcResource`
 * already serialize and compare at microsecond precision
 * (`toIso8601String(true)`); this migration is what makes that precision
 * genuinely present in the stored value for them to compare.
 *
 * Scoped to this one column, not every `timestampsTz()` column in the
 * schema: nothing else in the application uses a timestamp as a concurrency
 * token today, and widening unrelated columns is a different, unrequested
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE floor_plan_positions ALTER COLUMN updated_at TYPE timestamptz(6)');
    }

    public function down(): void
    {
        // Reverting to whole-second precision necessarily discards any
        // fractional-second data already stored — the same information loss
        // the original migration always implied for this column.
        DB::statement('ALTER TABLE floor_plan_positions ALTER COLUMN updated_at TYPE timestamptz(0)');
    }
};
