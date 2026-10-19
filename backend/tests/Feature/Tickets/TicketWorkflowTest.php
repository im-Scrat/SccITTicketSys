<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Models\ActivityLog;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * The full Teacher → Ticket → PC Unit → Administrator → Technician journey,
 * driven through the real API exactly as the three roles would.
 *
 * These are the integration scenarios from the Phase 2.6 brief, each an explicit
 * assertion rather than an implied consequence of the unit suites.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');

    $this->room = Room::factory()->create(['name' => 'Lab 3']);
    $this->pcUnit = PcUnit::factory()->create([
        'room_id' => $this->room->id,
        'pc_name' => 'Lab 3 Station 4',
        'unit_code' => 'LAB3-PC-04',
    ]);
});

it('carries a fault from report to confirmed resolution', function () {
    /* 1 ── The teacher reports a fault against a PC unit chosen from the
     *      narrow lookup built in Phase 2.5. */
    $created = $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Station 4 will not boot',
        'description' => 'The machine powers on but stops at a blue screen before Windows loads.',
        'category' => 'hardware',
        'pc_unit' => $this->pcUnit->uuid,
    ])->assertCreated();

    $uuid = $created->json('data.id');
    $ticket = Ticket::query()->where('uuid', $uuid)->firstOrFail();

    expect($ticket->pc_unit_id)->toBe($this->pcUnit->id)
        // The room is inherited from the machine — it already knows where it is.
        ->and($ticket->room_id)->toBe($this->room->id)
        ->and($ticket->status->slug)->toBe('open')
        ->and($ticket->ticket_number)->toStartWith('TKT-')
        // SLA derived from the category's default priority (FR-TKT-004).
        ->and($ticket->resolution_due_at)->not->toBeNull();

    /* 2 ── Another teacher discovers it in the feed, redacted. */
    $feed = $this->actingAs($this->otherTeacher)->getJson('/api/tickets/feed')->assertOk();
    expect(collect($feed->json('data'))->pluck('id'))->toContain($uuid);

    /* 3 ── …and participates instead of filing a duplicate. */
    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$uuid}/vote")
        ->assertOk()
        ->assertJsonPath('data.voted', true)
        ->assertJsonPath('data.upvote_count', 1);

    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$uuid}/comments", ['body' => 'Same here on Station 5 this morning.'])
        ->assertCreated();

    // The counters are the database triggers' work, not ours.
    expect($ticket->fresh()->upvote_count)->toBe(1)
        ->and($ticket->fresh()->comment_count)->toBe(1);

    /* 4 ── The administrator triages: raises priority, then assigns. */
    $this->actingAs($this->admin)
        ->putJson("/api/admin/tickets/{$uuid}/priority", ['priority' => 'high', 'reason' => 'Teaching lab.'])
        ->assertOk()
        ->assertJsonPath('data.priority.slug', 'high');

    $this->actingAs($this->admin)
        ->postJson("/api/admin/tickets/{$uuid}/assign", ['technician' => $this->technician->uuid])
        ->assertOk()
        ->assertJsonPath('data.status.slug', 'assigned');

    expect(TechnicianAssignment::query()
        ->where('ticket_id', $ticket->id)
        ->where('technician_id', $this->technician->id)
        ->where('status', AssignmentStatus::Pending->value)
        ->exists())->toBeTrue();

    /* 5 ── The assigned technician sees it and works it. */
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$uuid}")->assertOk();

    $this->actingAs($this->technician)->postJson("/api/tickets/assigned/{$uuid}/accept")->assertOk();
    $this->actingAs($this->technician)->postJson("/api/tickets/assigned/{$uuid}/start")
        ->assertOk()->assertJsonPath('data.status.slug', 'in-progress');

    $this->actingAs($this->technician)
        ->postJson("/api/tickets/{$uuid}/comments", ['body' => 'Failing RAM stick.', 'is_internal' => true])
        ->assertCreated();

    $this->actingAs($this->technician)
        ->postJson("/api/tickets/assigned/{$uuid}/complete", ['remarks' => 'Replaced the RAM.'])
        ->assertOk()->assertJsonPath('data.status.slug', 'resolved');

    /* 6 ── An unassigned technician can reach none of it. */
    $this->actingAs($this->otherTechnician)->getJson("/api/tickets/assigned/{$uuid}")->assertForbidden();
    $this->actingAs($this->otherTechnician)->getJson("/api/tickets/{$uuid}")->assertForbidden();

    /* 7 ── The teacher confirms, closing the ticket. */
    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$uuid}/status", ['status' => 'closed'])
        ->assertOk()->assertJsonPath('data.status.slug', 'closed');

    $ticket->refresh();
    expect($ticket->closed_at)->not->toBeNull()
        ->and($ticket->resolved_at)->not->toBeNull()
        ->and($ticket->first_response_at)->not->toBeNull();

    /* 8 ── Every step left a trail. */
    $history = TicketStatusHistory::query()->where('ticket_id', $ticket->id)->pluck('to_status_id');
    expect($history)->toHaveCount(5); // open, assigned, in-progress, resolved, closed

    $actions = ActivityLog::query()
        ->where('subject_id', $ticket->id)
        ->where('module', 'tickets')
        ->pluck('action');

    expect($actions)->toContain(ActivityAction::TicketCreated->value)
        ->toContain(ActivityAction::TicketPriorityChanged->value)
        ->toContain(ActivityAction::TicketAssigned->value)
        ->toContain(ActivityAction::TicketWorkStarted->value)
        ->toContain(ActivityAction::TicketWorkCompleted->value)
        ->toContain(ActivityAction::TicketResolutionConfirmed->value);
});

it('never lets a ticket endpoint become a route into Asset Management', function () {
    $ticket = ticketFor($this->teacher, 'assigned', ['pc_unit_id' => $this->pcUnit->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    foreach ([$this->teacher, $this->technician] as $actor) {
        // The asset module itself stays closed…
        $this->actingAs($actor)->getJson('/api/admin/assets')->assertForbidden();
        $this->actingAs($actor)->getJson("/api/admin/pc-units/{$this->pcUnit->uuid}")->assertForbidden();
    }

    // …and the ticket carries the machine as a *label*, never as asset data.
    $body = json_encode(
        $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")->assertOk()->json()
    );

    expect($body)->toContain('LAB3-PC-04')
        ->and($body)->not->toContain('serial_number')
        ->and($body)->not->toContain('warranty')
        ->and($body)->not->toContain('purchase')
        ->and($body)->not->toContain('specification');
});

it('returns a declined ticket to the queue and revokes the decliner’s access', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    $ticket->forceFill(['assigned_technician_id' => $this->technician->id])->save();
    assign($ticket, $this->technician, AssignmentStatus::Pending);

    $this->actingAs($this->technician)
        ->postJson("/api/tickets/assigned/{$ticket->uuid}/decline", ['reason' => 'I am off site all week.'])
        ->assertOk()
        ->assertJsonPath('data.status.slug', 'open');

    $ticket->refresh();
    expect($ticket->assigned_technician_id)->toBeNull();

    // Declining is a refusal of the work, so nothing is retained.
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")->assertForbidden();

    // And an administrator can place it elsewhere.
    $this->actingAs($this->admin)
        ->postJson("/api/admin/tickets/{$ticket->uuid}/assign", ['technician' => $this->otherTechnician->uuid])
        ->assertOk();

    expect($ticket->fresh()->assigned_technician_id)->toBe($this->otherTechnician->id);
});

it('keeps one active assignment per ticket when reassigning', function () {
    $ticket = ticketFor($this->teacher, 'open');

    $this->actingAs($this->admin)
        ->postJson("/api/admin/tickets/{$ticket->uuid}/assign", ['technician' => $this->technician->uuid])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/tickets/{$ticket->uuid}/assign", ['technician' => $this->otherTechnician->uuid])
        ->assertOk();

    // The partial unique index guarantees this; the prior row closes as
    // `reassigned` rather than being deleted, so the history survives.
    $active = TechnicianAssignment::query()
        ->where('ticket_id', $ticket->id)
        ->whereIn('status', AssignmentStatus::activeValues())
        ->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->technician_id)->toBe($this->otherTechnician->id)
        ->and(TechnicianAssignment::query()->where('ticket_id', $ticket->id)->count())->toBe(2);

    // The first technician keeps read access to work they were assigned…
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")->assertOk();
    // …but can no longer act on it.
    $this->actingAs($this->technician)->postJson("/api/tickets/assigned/{$ticket->uuid}/start")->assertForbidden();
});

it('refuses to assign a ticket to a teacher', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/tickets/{$ticket->uuid}/assign", ['technician' => $this->otherTeacher->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('technician');
});

it('surfaces likely duplicates before a second report is filed', function () {
    $existing = ticketFor($this->teacher, 'open', [
        'pc_unit_id' => $this->pcUnit->id,
        'title' => 'Station 4 will not boot',
        'description' => 'Blue screen on startup, will not reach the login prompt.',
    ]);

    $matches = $this->actingAs($this->otherTeacher)
        ->getJson('/api/tickets/duplicates?'.http_build_query([
            'search' => 'blue screen startup',
            'pc_unit' => $this->pcUnit->uuid,
        ]))
        ->assertOk()
        ->json('data');

    expect(collect($matches)->pluck('id'))->toContain($existing->uuid);
});

it('auto-closes a resolved ticket nobody confirmed', function () {
    $stale = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(30)]);
    $recent = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDay()]);

    $this->artisan('tickets:close-stale')->assertSuccessful();

    expect($stale->fresh()->status->slug)->toBe('closed')
        // Still inside the window — the requester has time to answer.
        ->and($recent->fresh()->status->slug)->toBe('resolved');

    // Attributed to the system, not to a person who did not act.
    $log = ActivityLog::query()
        ->where('subject_id', $stale->id)
        ->where('action', ActivityAction::TicketAutoClosed->value)
        ->firstOrFail();

    expect($log->user_id)->toBeNull();

    // And the reporter can still reopen it inside the reopen window.
    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$stale->uuid}/status", ['status' => 'open'])
        ->assertOk();
});

it('lets the reporter cancel before anyone is assigned, but not after', function () {
    $ticket = ticketFor($this->teacher, 'open');

    $assigned = ticketFor($this->teacher, 'open');
    $this->actingAs($this->admin)
        ->postJson("/api/admin/tickets/{$assigned->uuid}/assign", ['technician' => $this->technician->uuid])
        ->assertOk();

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$ticket->uuid}/status", ['status' => 'cancelled'])
        ->assertOk();

    $this->actingAs($this->teacher)
        ->putJson("/api/tickets/{$assigned->uuid}/status", ['status' => 'cancelled'])
        ->assertStatus(422);
});

it('shows the ticket on the PC unit timeline built in Phase 2.5', function () {
    $ticket = ticketFor($this->teacher, 'open', ['pc_unit_id' => $this->pcUnit->id]);

    // No Phase 2.6 code produces this — AssetHistory::forPcUnit() already reads
    // the tickets table, and Phase 2.6 simply gives it something to find.
    $entries = $this->actingAs($this->admin)
        ->getJson("/api/admin/pc-units/{$this->pcUnit->uuid}/history")
        ->assertOk()
        ->json('data');

    $ticketEntry = collect($entries)->firstWhere('type', 'ticket');

    expect($ticketEntry)->not->toBeNull()
        ->and($ticketEntry['label'])->toContain($ticket->ticket_number);
});
