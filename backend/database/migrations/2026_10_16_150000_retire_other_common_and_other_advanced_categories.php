<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.9 — one "Other" on the report form (Client decision 2026-10-16).
 *
 * `2026_10_13_100000` gave each category group its own catch-all ("Other
 * Common Issues", "Other Advanced Issues") next to the legacy "Other". The
 * Client asked for a single "Other" under "Other Categories" instead: a
 * teacher who cannot place the fault should face one obvious choice, and the
 * description field that "Other" reveals (SDD DD-77) already carries the
 * specifics the two group-level catch-alls were meant to capture.
 *
 * **Retired, not deleted.** The two rows are switched off (`is_active = false`).
 * Every list that offers categories — the options endpoint behind the report
 * form, the edit drawer and the filter bar — reads active rows only, so they
 * disappear everywhere a person chooses one. Deleting them would fail on any
 * install where a ticket was filed under them (`tickets.category_id` is
 * ON DELETE RESTRICT) and would erase what those tickets were filed as;
 * an inactive row keeps their history readable. On 2026-10-16 production had
 * no tickets in either.
 *
 * `requires_detail` stays set on them (`2026_10_16_120000`): harmless while
 * they are off, and right again if they are ever switched back on.
 *
 * **What it touches.** `ticket_categories.is_active` (and `updated_at`) on the
 * rows in {@see RETIRED}, only where they are still active, so re-running
 * `up()` changes nothing. The list is frozen: a migration must keep meaning
 * what it meant when it ran. `TicketLookupSeeder` no longer creates these two
 * rows, so a later seed cannot switch them back on.
 *
 * `down()` switches the same rows back on.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const RETIRED = ['other-common', 'other-advanced'];

    public function up(): void
    {
        DB::table('ticket_categories')
            ->whereIn('slug', self::RETIRED)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('ticket_categories')
            ->whereIn('slug', self::RETIRED)
            ->where('is_active', false)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
