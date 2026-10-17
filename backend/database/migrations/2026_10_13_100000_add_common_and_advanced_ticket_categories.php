<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ticket categories — the "Common Problems" and "Advanced / Uncommon Problems"
 * vocabulary the ticket filter groups (SRS FR-TKT-001/014).
 *
 * **Why a migration and not just the seeder.** `TicketLookupSeeder` runs on first
 * install only, and re-running it against a live database is not safe: it
 * `updateOrCreate`s the priorities and statuses too, resetting any colour or SLA
 * an administrator tuned, and re-activating any category they switched off.
 * Adding rows is therefore done here, where `deploy-prod.sh` already runs. The
 * seeder carries the same list so a fresh install and a migrated one converge.
 *
 * **What it touches.**
 *  - Inserts the ten new categories, each only when its slug is absent. An
 *    existing row keeps its name and its `is_active` flag — nothing an
 *    administrator set is overwritten.
 *  - Never inserts the six pre-existing categories (`hardware`, `software`,
 *    `network`, `peripheral`, `account-access`, `other`). Those belong to the
 *    seeder; on a fresh database they are created there, and creating them here
 *    would seed lookup rows into a database that has not been seeded.
 *  - Rewrites `sort_order` on whichever of the sixteen slugs exist, so the report
 *    form — which lists categories flat in `sort_order` — reads in the same order
 *    as the filter instead of leaving `Other` stranded in the middle. This is the
 *    one write to an existing row, and it is ordering only.
 *  - Leaves `other` in place, last. It pre-dates the two-group scheme, belongs to
 *    neither group, and is referenced by tickets (`category_id` is RESTRICT).
 *
 * New categories carry no `default_priority_id`, exactly like the six already
 * seeded: `CreateTicket` then falls back to Medium.
 *
 * `down()` drops only the rows this migration added and only while no ticket
 * references them, then restores the original ordering. A category already in use
 * is left behind rather than failing the rollback on the foreign key.
 */
return new class extends Migration
{
    /**
     * Presentation order of every known slug. Frozen here on purpose: a migration
     * must keep meaning what it meant when it ran, even if the seeder later moves.
     *
     * @var list<string>
     */
    private const ORDER = [
        'hardware', 'software', 'network',
        'performance-slowness', 'os-updates', 'power-issue', 'audio-video-output', 'browser-web', 'other-common',
        'peripheral', 'account-access',
        'bios-firmware', 'blue-screen', 'missing-software', 'other-advanced',
        'other',
    ];

    /**
     * The categories this migration introduces: slug => name.
     *
     * @var array<string, string>
     */
    private const ADDED = [
        'performance-slowness' => 'Performance & System Slowness',
        'os-updates' => 'Operating System Updates',
        'power-issue' => 'Power Issue',
        'audio-video-output' => 'Audio & Video Output',
        'browser-web' => 'Browser & Web Issues',
        'other-common' => 'Other Common Issues',
        'bios-firmware' => 'BIOS / Firmware errors',
        'blue-screen' => 'Blue Screen / Kernel Panic',
        'missing-software' => 'Missing Software',
        'other-advanced' => 'Other Advanced Issues',
    ];

    /** The order before this migration, restored by `down()`. */
    private const ORIGINAL_ORDER = [
        'hardware', 'software', 'network', 'peripheral', 'account-access', 'other',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ORDER as $position => $slug) {
            $row = DB::table('ticket_categories')->where('slug', $slug);

            if (isset(self::ADDED[$slug]) && ! $row->exists()) {
                DB::table('ticket_categories')->insert([
                    'name' => self::ADDED[$slug],
                    'slug' => $slug,
                    'is_active' => true,
                    'sort_order' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $row->where('sort_order', '<>', $position)
                ->update(['sort_order' => $position, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('ticket_categories')
            ->whereIn('slug', array_keys(self::ADDED))
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('tickets')
                ->whereColumn('tickets.category_id', 'ticket_categories.id'))
            ->delete();

        foreach (self::ORIGINAL_ORDER as $position => $slug) {
            DB::table('ticket_categories')->where('slug', $slug)->update(['sort_order' => $position]);
        }
    }
};
