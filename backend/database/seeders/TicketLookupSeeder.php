<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use Illuminate\Database\Seeder;

class TicketLookupSeeder extends Seeder
{
    public function run(): void
    {
        // Listed in the order the ticket filter presents them: the "Common
        // Problems" group, then "Advanced / Uncommon Problems", then the single
        // `other` row. The grouping itself lives in the frontend
        // (`features/tickets/categoryGroups.ts`) and is keyed on these slugs —
        // change a slug here and that map must change with it. The group rows are
        // frozen in `2026_10_13_100000_add_common_and_advanced_ticket_categories`,
        // which is how an already-installed database receives them.
        //
        // `other-common` and `other-advanced` are deliberately absent: that
        // migration still inserts them, and
        // `2026_10_16_150000_retire_other_common_and_other_advanced_categories`
        // switches them off (Client decision 2026-10-16: one "Other"). Listing
        // them here would switch them back on, because this seeder forces every
        // row it names to active.
        // `problem_area` (owner decision D6, SDD DD-87) is the mapping the owner
        // approved on 2026-10-18, frozen in
        // `2026_10_18_100000_add_problem_area_to_ticket_categories` for installed
        // databases; a test keeps this list and that migration equal.
        $categories = [
            ['name' => 'Hardware', 'slug' => 'hardware', 'problem_area' => 'hardware'],
            ['name' => 'Software', 'slug' => 'software', 'problem_area' => 'software'],
            ['name' => 'Network', 'slug' => 'network', 'problem_area' => 'network'],
            ['name' => 'Performance & System Slowness', 'slug' => 'performance-slowness', 'problem_area' => 'software'],
            ['name' => 'Operating System Updates', 'slug' => 'os-updates', 'problem_area' => 'software'],
            ['name' => 'Power Issue', 'slug' => 'power-issue', 'problem_area' => 'hardware'],
            ['name' => 'Audio & Video Output', 'slug' => 'audio-video-output', 'problem_area' => 'hardware'],
            ['name' => 'Browser & Web Issues', 'slug' => 'browser-web', 'problem_area' => 'software'],
            ['name' => 'Peripheral', 'slug' => 'peripheral', 'problem_area' => 'hardware'],
            ['name' => 'Account & Access', 'slug' => 'account-access', 'problem_area' => 'other'],
            ['name' => 'BIOS / Firmware errors', 'slug' => 'bios-firmware', 'problem_area' => 'hardware'],
            ['name' => 'Blue Screen / Kernel Panic', 'slug' => 'blue-screen', 'problem_area' => 'software'],
            ['name' => 'Missing Software', 'slug' => 'missing-software', 'problem_area' => 'software'],
            // The one catch-all, offered under "Other Categories". Kept rather
            // than retired: tickets reference it (`tickets.category_id` is
            // ON DELETE RESTRICT).
            ['name' => 'Other', 'slug' => 'other', 'problem_area' => 'other'],
        ];

        // The catch-all asks the reporter to describe the kind of problem in a
        // few words (SDD DD-77). Frozen in
        // `2026_10_16_120000_add_requires_detail_to_ticket_categories`, which is
        // how an installed database is flagged — but that migration runs before
        // any seeding, when legacy `other` does not exist yet, so on a fresh
        // install this list is what flags it.
        $requiresDetail = ['other'];

        foreach ($categories as $i => $c) {
            TicketCategory::query()->updateOrCreate(['slug' => $c['slug']], [
                ...$c,
                'is_active' => true,
                'sort_order' => $i,
                'requires_detail' => in_array($c['slug'], $requiresDetail, true),
            ]);
        }

        // The top level is *shown* as "Urgent" (SDD DD-76, Client decision
        // 2026-10-16); its slug stays `critical`, which is what everything keys
        // on. An installed database is renamed by
        // `2026_10_16_110000_rename_critical_priority_to_urgent`; that migration
        // finds no priorities on a fresh database, so this row carries the name.
        $priorities = [
            ['name' => 'Low', 'slug' => 'low', 'level' => 1, 'color' => '#22c55e', 'response_time_minutes' => 480, 'resolution_time_minutes' => 4320],
            ['name' => 'Medium', 'slug' => 'medium', 'level' => 2, 'color' => '#eab308', 'response_time_minutes' => 240, 'resolution_time_minutes' => 2880],
            ['name' => 'High', 'slug' => 'high', 'level' => 3, 'color' => '#f97316', 'response_time_minutes' => 60, 'resolution_time_minutes' => 1440],
            ['name' => 'Urgent', 'slug' => 'critical', 'level' => 4, 'color' => '#ef4444', 'response_time_minutes' => 15, 'resolution_time_minutes' => 480],
        ];
        foreach ($priorities as $p) {
            TicketPriority::query()->updateOrCreate(['slug' => $p['slug']], [...$p, 'is_active' => true]);
        }

        $statuses = [
            ['name' => 'Open', 'slug' => 'open', 'color' => '#3b82f6', 'is_default' => true, 'is_open' => true, 'is_terminal' => false],
            ['name' => 'Assigned', 'slug' => 'assigned', 'color' => '#6366f1', 'is_default' => false, 'is_open' => true, 'is_terminal' => false],
            ['name' => 'In Progress', 'slug' => 'in-progress', 'color' => '#8b5cf6', 'is_default' => false, 'is_open' => true, 'is_terminal' => false],
            ['name' => 'On Hold', 'slug' => 'on-hold', 'color' => '#f59e0b', 'is_default' => false, 'is_open' => true, 'is_terminal' => false],
            ['name' => 'Resolved', 'slug' => 'resolved', 'color' => '#10b981', 'is_default' => false, 'is_open' => true, 'is_terminal' => false],
            ['name' => 'Closed', 'slug' => 'closed', 'color' => '#6b7280', 'is_default' => false, 'is_open' => false, 'is_terminal' => true],
            ['name' => 'Cancelled', 'slug' => 'cancelled', 'color' => '#9ca3af', 'is_default' => false, 'is_open' => false, 'is_terminal' => true],
        ];
        foreach ($statuses as $i => $s) {
            TicketStatus::query()->updateOrCreate(['slug' => $s['slug']], [...$s, 'sort_order' => $i]);
        }
    }
}
