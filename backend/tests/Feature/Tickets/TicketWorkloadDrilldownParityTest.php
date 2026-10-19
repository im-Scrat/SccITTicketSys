<?php

declare(strict_types=1);

use App\Models\TicketStatus;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * Admin Ticket Management: a "Who is carrying what" row is a link into the
 * directory, and the list it opens is exactly the tickets its count was made
 * from (owner request 2026-10-19; same rule as SDD DD-81 for the tiles).
 *
 * The page builds the link as `technician=<uuid>&status=<every is_open slug>`
 * from the ticket options. This pins the server half: those parameters select
 * the same set `TicketMetrics::technicianWorkload` counts — open statuses only
 * (Resolved included, Closed/Cancelled not), deleted tickets excluded.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');

    $mine = ['assigned_technician_id' => $this->technician->id];

    $this->counted = [
        ticketFor($this->teacher, 'assigned', $mine),
        ticketFor($this->teacher, 'in-progress', $mine),
        ticketFor($this->teacher, 'resolved', $mine),
    ];

    ticketFor($this->teacher, 'closed', $mine);
    ticketFor($this->teacher, 'cancelled', $mine);
    ticketFor($this->teacher, 'in-progress', $mine)->delete();
    ticketFor($this->teacher, 'in-progress', ['assigned_technician_id' => $this->otherTechnician->id]);
});

it('opens a workload row as exactly the tickets it counts', function () {
    $workload = collect($this->actingAs($this->admin)->getJson('/api/admin/tickets/dashboard')
        ->assertOk()->json('data.technician_workload'))
        ->firstWhere('id', $this->technician->uuid);

    $openSlugs = TicketStatus::query()->where('is_open', true)->pluck('slug')->implode(',');

    $listed = $this->actingAs($this->admin)
        ->getJson('/api/admin/tickets?'.http_build_query([
            'technician' => $this->technician->uuid,
            'status' => $openSlugs,
            'per_page' => 100,
        ]))
        ->assertOk();

    expect($workload['count'])->toBe(3)
        ->and($listed->json('meta.total'))->toBe($workload['count'])
        ->and(collect($listed->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing(collect($this->counted)->pluck('uuid')->all());
});
