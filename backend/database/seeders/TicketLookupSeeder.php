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
        $categories = [
            ['name' => 'Hardware', 'slug' => 'hardware'],
            ['name' => 'Software', 'slug' => 'software'],
            ['name' => 'Network', 'slug' => 'network'],
            ['name' => 'Peripheral', 'slug' => 'peripheral'],
            ['name' => 'Account & Access', 'slug' => 'account-access'],
            ['name' => 'Other', 'slug' => 'other'],
        ];
        foreach ($categories as $i => $c) {
            TicketCategory::query()->updateOrCreate(['slug' => $c['slug']], [...$c, 'is_active' => true, 'sort_order' => $i]);
        }

        $priorities = [
            ['name' => 'Low', 'slug' => 'low', 'level' => 1, 'color' => '#22c55e', 'response_time_minutes' => 480, 'resolution_time_minutes' => 4320],
            ['name' => 'Medium', 'slug' => 'medium', 'level' => 2, 'color' => '#eab308', 'response_time_minutes' => 240, 'resolution_time_minutes' => 2880],
            ['name' => 'High', 'slug' => 'high', 'level' => 3, 'color' => '#f97316', 'response_time_minutes' => 60, 'resolution_time_minutes' => 1440],
            ['name' => 'Critical', 'slug' => 'critical', 'level' => 4, 'color' => '#ef4444', 'response_time_minutes' => 15, 'resolution_time_minutes' => 480],
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
