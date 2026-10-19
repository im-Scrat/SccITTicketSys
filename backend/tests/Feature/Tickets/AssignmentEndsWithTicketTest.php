<?php

declare(strict_types=1);

use App\Domains\Analytics\Services\TicketMetrics;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Models\ActivityLog;
use App\Models\TechnicianAssignment;
use App\Models\TicketStatusHistory;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\DB;

/*
 * SDD DD-83 — an assignment ends with its ticket.
 *
 * The generic "Move this ticket" control moves only the ticket, so a job resolved
 * through it and then confirmed by the reporter used to close with its assignment
 * still `pending`: the closed ticket sat in the technician's active queue and the
 * technician kept the write access `canWork` grants. Every terminal path is
 * exercised here, driven through the real lifecycle so the history the rule reads
 * is the history a real ticket would carry.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->lifecycle = app(TicketLifecycle::class);

    /** Move a ticket as a named actor, five minutes after the previous move. */
    $this->move = function ($ticket, string $actor, string $slug): void {
        $this->travel(5)->minutes();

        $who = match ($actor) {
            'admin' => $this->admin,
            'technician' => $this->technician,
            'teacher' => $this->teacher,
            'system' => null,
        };

        $this->lifecycle->transition($ticket->fresh(), ticketStatus($slug), $who, 'test move');
    };

    /** A ticket carrying one pending assignment for $this->technician. */
    $this->workedTicket = function (string $start) {
        $ticket = ticketFor($this->teacher, $start, ['assigned_technician_id' => $this->technician->id]);
        $assignment = assign($ticket, $this->technician, AssignmentStatus::Pending);

        return [$ticket, $assignment];
    };
});

/** Each terminal path: [start status, moves as [actor, slug], expected assignment status]. */
dataset('terminal paths', [
    'assignee resolves, reporter closes' => ['assigned', [['technician', 'in-progress'], ['technician', 'resolved'], ['teacher', 'closed']], 'completed'],
    'assignee resolves, admin closes' => ['assigned', [['technician', 'in-progress'], ['technician', 'resolved'], ['admin', 'closed']], 'completed'],
    'assignee resolves, system auto-closes' => ['assigned', [['technician', 'in-progress'], ['technician', 'resolved'], ['system', 'closed']], 'completed'],
    'admin resolves, then closes' => ['assigned', [['admin', 'in-progress'], ['admin', 'resolved'], ['admin', 'closed']], 'cancelled'],
    'admin cancels from assigned' => ['assigned', [['admin', 'cancelled']], 'cancelled'],
    'admin cancels from in-progress' => ['assigned', [['technician', 'in-progress'], ['admin', 'cancelled']], 'cancelled'],
    'admin cancels from on-hold' => ['assigned', [['technician', 'on-hold'], ['admin', 'cancelled']], 'cancelled'],
    'open ticket with a lingering assignment is closed' => ['open', [['admin', 'closed']], 'cancelled'],
    'open ticket with a lingering assignment is cancelled' => ['open', [['admin', 'cancelled']], 'cancelled'],
]);

it('ends the active assignment on every terminal path', function (string $start, array $moves, string $expected) {
    [$ticket, $assignment] = ($this->workedTicket)($start);

    $updatedBefore = DB::table('technician_assignments')->where('id', $assignment->id)->value('updated_at');
    $load = app(TicketMetrics::class);
    expect($load->assignmentLoad($this->technician)['active'])->toBe(1);

    foreach ($moves as [$actor, $slug]) {
        ($this->move)($ticket, $actor, $slug);
    }

    $assignment->refresh();
    expect($assignment->status->value)->toBe($expected);

    // completed_at is the resolution move's time, not the moment of closing.
    if ($expected === 'completed') {
        $resolvedAt = TicketStatusHistory::query()
            ->where('ticket_id', $ticket->id)
            ->where('to_status_id', ticketStatus('resolved')->id)
            ->latest('id')
            ->value('created_at');

        expect($assignment->completed_at?->equalTo($resolvedAt))->toBeTrue();
    } else {
        expect($assignment->completed_at)->toBeNull();
    }

    // Monitoring reads updated_at as the technician's last activity; the system
    // ending an assignment is not one of their actions.
    expect(DB::table('technician_assignments')->where('id', $assignment->id)->value('updated_at'))
        ->toBe($updatedBefore);

    // The "active assignments" figure and the queue move together.
    expect($load->assignmentLoad($this->technician)['active'])->toBe(0);

    $uuid = $ticket->uuid;
    $queue = $this->actingAs($this->technician)->getJson('/api/tickets/assigned')->assertOk();
    expect(collect($queue->json('data'))->pluck('id'))->not->toContain($uuid)
        ->and($queue->json('meta.total'))->toBe(0);

    $history = $this->actingAs($this->technician)->getJson('/api/tickets/assigned/history')->assertOk();
    expect(collect($history->json('data'))->pluck('id'))->toContain($uuid);

    // Still readable, no longer writable.
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$uuid}")
        ->assertOk()
        ->assertJsonPath('meta.read_only', true);

    foreach (['accept', 'start', 'hold', 'complete'] as $action) {
        $this->actingAs($this->technician)->postJson("/api/tickets/assigned/{$uuid}/{$action}")
            ->assertForbidden();
    }
    $this->actingAs($this->technician)
        ->postJson("/api/tickets/assigned/{$uuid}/decline", ['reason' => 'No longer needed here.'])
        ->assertForbidden();

    // The audit row.
    $audit = ActivityLog::query()
        ->where('subject_id', $ticket->id)
        ->where('action', ActivityAction::TicketAssignmentEnded->value)
        ->get();
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->properties['assignment_status'])->toBe($expected)
        ->and($audit->first()->module)->toBe('tickets');
})->with('terminal paths');

it('shows staff a System timeline row for the ended assignment and hides it from the reporter', function () {
    [$ticket] = ($this->workedTicket)('assigned');

    ($this->move)($ticket, 'technician', 'in-progress');
    ($this->move)($ticket, 'technician', 'resolved');
    ($this->move)($ticket, 'teacher', 'closed');

    $staff = $this->actingAs($this->admin)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertOk();
    $ended = collect($staff->json('data'))->firstWhere('assignment_status', 'completed');

    expect($ended)->not->toBeNull()
        ->and($ended['kind'])->toBe('assignment_response')
        ->and($ended['actor'])->toBe('System')
        ->and($ended['technician'])->toBe($this->technician->fullName());

    $reporter = $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertOk();
    $encoded = json_encode($reporter->json('data'), JSON_THROW_ON_ERROR);

    // Asserted on the encoded payload, so the row cannot leak back in through a
    // later change to the projection.
    expect(collect($reporter->json('data'))->pluck('kind')->all())->not->toContain('assignment_response')
        ->and($encoded)->not->toContain('assignment_status')
        ->and($encoded)->not->toContain($this->technician->fullName());
});

/* ------------------------------------------------------ must not change */

it('leaves the assignment alone on non-terminal moves', function () {
    [$ticket, $assignment] = ($this->workedTicket)('assigned');

    ($this->move)($ticket, 'technician', 'in-progress');
    ($this->move)($ticket, 'technician', 'resolved');

    // Resolved is awaiting confirmation, not terminal: the reporter can still
    // send it back, so the technician keeps the job until it closes.
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Pending)
        ->and(ActivityLog::query()->where('action', ActivityAction::TicketAssignmentEnded->value)->exists())->toBeFalse();
});

it('leaves a completed row untouched when the normal Complete button was used', function () {
    [$ticket, $assignment] = ($this->workedTicket)('assigned');

    $this->actingAs($this->technician)->postJson("/api/tickets/assigned/{$ticket->uuid}/start")->assertOk();
    $this->actingAs($this->technician)->postJson("/api/tickets/assigned/{$ticket->uuid}/complete", ['remarks' => 'Fixed.'])->assertOk();

    $completedAt = $assignment->fresh()->completed_at;
    $updatedAt = DB::table('technician_assignments')->where('id', $assignment->id)->value('updated_at');

    ($this->move)($ticket, 'teacher', 'closed');

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Completed)
        ->and($assignment->fresh()->completed_at->equalTo($completedAt))->toBeTrue()
        ->and(DB::table('technician_assignments')->where('id', $assignment->id)->value('updated_at'))->toBe($updatedAt)
        ->and(ActivityLog::query()->where('action', ActivityAction::TicketAssignmentEnded->value)->exists())->toBeFalse();
});

it('does not revive an ended assignment when the ticket is reopened', function () {
    [$ticket, $assignment] = ($this->workedTicket)('assigned');

    ($this->move)($ticket, 'technician', 'in-progress');
    ($this->move)($ticket, 'technician', 'resolved');
    ($this->move)($ticket, 'teacher', 'closed');
    ($this->move)($ticket, 'teacher', 'open');

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Completed)
        ->and(TechnicianAssignment::query()->where('ticket_id', $ticket->id)->count())->toBe(1);

    $this->actingAs($this->technician)->getJson('/api/tickets/assigned')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('does not touch another technician’s assignment on a different ticket', function () {
    $other = userWithRole('technician');
    [$ticket] = ($this->workedTicket)('assigned');

    $elsewhere = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $other->id]);
    $theirs = assign($elsewhere, $other, AssignmentStatus::Accepted);

    ($this->move)($ticket, 'admin', 'cancelled');

    expect($theirs->fresh()->status)->toBe(AssignmentStatus::Accepted);
});

it('does nothing on a terminal move when no assignment is active', function () {
    $ticket = ticketFor($this->teacher, 'open');

    ($this->move)($ticket, 'admin', 'cancelled');

    expect(ActivityLog::query()->where('action', ActivityAction::TicketAssignmentEnded->value)->exists())->toBeFalse();
});
