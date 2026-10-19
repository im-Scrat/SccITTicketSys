<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.9 — report-a-problem form (SDD DD-76; Client decision 2026-10-16):
 * the top priority level is shown to people as **"Urgent"**, not "Critical".
 *
 * **Names are display data; the slug is the contract.** Everything that
 * *means* something keys on `critical` — the AI's recommendation vocabulary,
 * the frontend's tone map, SLA reports, `ticket_updates.metadata.to` — so only
 * the `name` column changes. Slug, level 4, colour and the 15/480-minute SLA
 * budget are untouched.
 *
 * **Why a migration and not just the seeder.** `TicketLookupSeeder` runs on
 * first install only (re-running it resets every colour and SLA an
 * administrator tuned), so an installed database receives the new name here.
 * The seeder carries the same name, so a fresh install — where this migration
 * finds no priorities yet and does nothing — converges on it.
 *
 * **What it touches.** One row, and only while all of these hold:
 *  - its slug is `critical` **and** its name is still exactly "Critical" — an
 *    administrator who already renamed the level keeps their name;
 *  - no other row is already called "Urgent" — `ticket_priorities.name` is
 *    unique, so renaming into a collision would fail the deploy.
 *
 * **What it never touches.** `ticket_updates.metadata.*_label` and
 * `activity_logs` keep the word "Critical" where it was recorded: history is
 * append-only (FR-TKT-012) and says what the level was *called* at the time.
 *
 * `down()` is the guarded mirror: "Urgent" back to "Critical", under the same
 * two conditions. Both directions are idempotent.
 */
return new class extends Migration
{
    private const SLUG = 'critical';

    private const FROM = 'Critical';

    private const TO = 'Urgent';

    public function up(): void
    {
        $this->rename(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->rename(self::TO, self::FROM);
    }

    private function rename(string $from, string $to): void
    {
        DB::table('ticket_priorities')
            ->where('slug', self::SLUG)
            ->where('name', $from)
            ->whereNotExists(fn (Builder $query) => $query
                ->select(DB::raw(1))
                ->from('ticket_priorities as other')
                ->where('other.name', $to))
            ->update(['name' => $to, 'updated_at' => now()]);
    }
};
