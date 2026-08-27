<?php

declare(strict_types=1);

use App\Domains\Tickets\Services\SlaCalculator;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Models\TicketPriority;
use App\Models\TicketStatusHistory;
use App\Models\TicketUpdate;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->lifecycle = app(TicketLifecycle::class);
});

/* ---------------------------------------------------------- legal moves */

it('records a transition in history, updates and audit atomically', function () {
    $ticket = ticketFor($this->teacher, 'open');

    $this->lifecycle->transition($ticket, ticketStatus('assigned'), $this->admin, 'Assigned to the lab team.');

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('assigned');

    $history = TicketStatusHistory::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
    expect($history->from_status_id)->toBe(ticketStatus('open')->id)
        ->and($history->to_status_id)->toBe(ticketStatus('assigned')->id)
        ->and($history->changed_by)->toBe($this->admin->id)
        ->and($history->remarks)->toBe('Assigned to the lab team.');

    expect(TicketUpdate::query()
        ->where('ticket_id', $ticket->id)
        ->where('update_type', 'status_change')
        ->exists())->toBeTrue();

    expect(ActivityLog::query()
        ->where('subject_id', $ticket->id)
        ->where('action', ActivityAction::TicketStatusChanged->value)
        ->where('module', 'tickets')
        ->exists())->toBeTrue();
});

it('treats re-submitting the current status as a no-op', function () {
    $ticket = ticketFor($this->teacher, 'open');
    $before = TicketStatusHistory::query()->where('ticket_id', $ticket->id)->count();

    $this->lifecycle->transition($ticket, ticketStatus('open'), $this->admin);

    expect(TicketStatusHistory::query()->where('ticket_id', $ticket->id)->count())->toBe($before);
});

/* -------------------------------------------------------- illegal moves */

it('refuses an illegal transition and names the reachable states', function () {
    $ticket = ticketFor($this->teacher, 'open');

    expect(fn () => $this->lifecycle->transition($ticket, ticketStatus('resolved'), $this->admin))
        ->toThrow(ValidationException::class);

    expect($ticket->fresh()->status->slug)->toBe('open');
});

it('treats cancelled as absorbing', function () {
    $ticket = ticketFor($this->teacher, 'cancelled');

    expect($this->lifecycle->availableTransitions($ticket, $this->admin))->toBeEmpty()
        ->and(fn () => $this->lifecycle->transition($ticket, ticketStatus('open'), $this->admin))
        ->toThrow(ValidationException::class);
});

/* -------------------------------------------------------- actor authority */

it('refuses a transition the actor is not entitled to make', function () {
    $ticket = ticketFor($this->teacher, 'in-progress');

    // A technician with no assignment on this ticket is nobody here.
    expect(fn () => $this->lifecycle->transition($ticket, ticketStatus('resolved'), $this->technician))
        ->toThrow(ValidationException::class);

    // The reporter may not resolve their own ticket either — that is the
    // technician's call; the reporter only *confirms* afterwards.
    expect(fn () => $this->lifecycle->transition($ticket, ticketStatus('resolved'), $this->teacher))
        ->toThrow(ValidationException::class);
});

it('lets the assigned technician resolve but not an unassigned one', function () {
    $ticket = ticketFor($this->teacher, 'in-progress');
    assign($ticket, $this->technician, AssignmentStatus::InProgress);

    $other = userWithRole('technician');
    expect(fn () => $this->lifecycle->transition($ticket, ticketStatus('resolved'), $other))
        ->toThrow(ValidationException::class);

    $this->lifecycle->transition($ticket, ticketStatus('resolved'), $this->technician);
    expect($ticket->fresh()->status->slug)->toBe('resolved');
});

it('stops a technician working a ticket once their assignment completed', function () {
    $ticket = ticketFor($this->teacher, 'in-progress');
    assign($ticket, $this->technician, AssignmentStatus::Completed);

    // Read access survives completion; write access does not (SDD DD-42).
    expect(fn () => $this->lifecycle->transition($ticket, ticketStatus('on-hold'), $this->technician))
        ->toThrow(ValidationException::class);
});

/* ------------------------------------------------ reporter-owned moves */

it('lets the reporter confirm a resolution, closing the ticket', function () {
    $ticket = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDay()]);

    $this->lifecycle->transition($ticket, ticketStatus('closed'), $this->teacher, null, null, ActivityAction::TicketResolutionConfirmed);

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('closed')
        ->and($ticket->closed_at)->not->toBeNull();
});

it('lets the reporter reopen and clears the terminal stamps', function () {
    $ticket = ticketFor($this->teacher, 'closed', [
        'resolved_at' => now()->subDays(2),
        'closed_at' => now()->subDay(),
    ]);

    $this->lifecycle->transition($ticket, ticketStatus('open'), $this->teacher, null, null, ActivityAction::TicketReopened);

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('open')
        ->and($ticket->reopened_at)->not->toBeNull()
        // Cleared, so the auto-close sweep cannot pick the ticket up again.
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->closed_at)->toBeNull();
});

it('refuses a reporter reopen once the window has closed', function () {
    SystemSetting::query()->where('key', 'tickets.reopen_window_days')->update(['value' => 7]);

    $ticket = ticketFor($this->teacher, 'closed', ['closed_at' => now()->subDays(30)]);

    expect(fn () => $this->lifecycle->transition($ticket, ticketStatus('open'), $this->teacher))
        ->toThrow(ValidationException::class);

    // An administrator is not bound by the requester's window.
    $this->lifecycle->transition($ticket, ticketStatus('open'), $this->admin);
    expect($ticket->fresh()->status->slug)->toBe('open');
});

it('lets the reporter cancel an unassigned open ticket only', function () {
    $ticket = ticketFor($this->teacher, 'open');
    $this->lifecycle->transition($ticket, ticketStatus('cancelled'), $this->teacher);
    expect($ticket->fresh()->status->slug)->toBe('cancelled');

    $assigned = ticketFor($this->teacher, 'assigned');
    expect(fn () => $this->lifecycle->transition($assigned, ticketStatus('cancelled'), $this->teacher))
        ->toThrow(ValidationException::class);
});

/* --------------------------------------------------------- system actor */

it('allows the scheduler to auto-close a resolved ticket', function () {
    $ticket = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(20)]);

    // A null actor is the scheduled sweep, and it is entitled to exactly one
    // transition: resolved -> closed.
    $this->lifecycle->transition($ticket, ticketStatus('closed'), null, 'Closed automatically.', null, ActivityAction::TicketAutoClosed);

    $ticket->refresh();
    expect($ticket->status->slug)->toBe('closed')
        ->and(ActivityLog::query()
            ->where('subject_id', $ticket->id)
            ->where('action', ActivityAction::TicketAutoClosed->value)
            ->exists())->toBeTrue();
});

it('does not let the scheduler make any other transition', function () {
    $ticket = ticketFor($this->teacher, 'open');

    expect(fn () => $this->lifecycle->transition($ticket, ticketStatus('assigned'), null))
        ->toThrow(ValidationException::class);
});

/* ------------------------------------------------------ available moves */

it('offers each actor only the transitions they may make', function () {
    $ticket = ticketFor($this->teacher, 'in-progress');
    assign($ticket, $this->technician, AssignmentStatus::InProgress);

    $adminMoves = collect($this->lifecycle->availableTransitions($ticket, $this->admin))->pluck('value');
    $techMoves = collect($this->lifecycle->availableTransitions($ticket, $this->technician))->pluck('value');
    $teacherMoves = collect($this->lifecycle->availableTransitions($ticket, $this->teacher))->pluck('value');

    expect($adminMoves)->toContain('resolved')->toContain('assigned')->toContain('cancelled')
        ->and($techMoves)->toContain('resolved')->toContain('on-hold')
        // A technician may not reassign or cancel — that is the admin's authority.
        ->and($techMoves)->not->toContain('assigned')
        ->and($techMoves)->not->toContain('cancelled')
        // The reporter has no move at all while work is in progress.
        ->and($teacherMoves)->toBeEmpty();
});

/* ------------------------------------------------------- first response */

it('stamps first_response_at once, on the first staff response', function () {
    $ticket = ticketFor($this->teacher, 'open');
    expect($ticket->first_response_at)->toBeNull();

    $this->lifecycle->transition($ticket, ticketStatus('assigned'), $this->admin);
    $first = $ticket->fresh()->first_response_at;
    expect($first)->not->toBeNull();

    $this->lifecycle->transition($ticket->fresh(), ticketStatus('in-progress'), $this->admin);
    expect($ticket->fresh()->first_response_at->timestamp)->toBe($first->timestamp);
});

/* ------------------------------------------------------------------ SLA */

it('derives deadlines from the priority and re-anchors them on change', function () {
    $sla = app(SlaCalculator::class);
    $low = TicketPriority::query()->where('slug', 'low')->firstOrFail();
    $critical = TicketPriority::query()->where('slug', 'critical')->firstOrFail();

    $reportedAt = now()->subHours(6);
    $ticket = ticketFor($this->teacher, 'open', [
        'priority_id' => $low->id,
        'created_at' => $reportedAt,
    ]);

    $escalated = $sla->recalculate($ticket, $critical);

    // Anchored to the report time, not to the escalation — slow triage must not
    // buy a longer deadline.
    expect($escalated['resolution_due_at']->timestamp)
        ->toBe($reportedAt->copy()->addMinutes($critical->resolution_time_minutes)->timestamp);
});

it('reports breach only on live tickets', function () {
    $sla = app(SlaCalculator::class);

    $breached = ticketFor($this->teacher, 'in-progress', [
        'resolution_due_at' => now()->subHour(),
    ]);
    expect($sla->posture($breached)['breached'])->toBeTrue();

    // A closed ticket has no live clock — reporting it breached forever would
    // make every historical ticket look like a failure.
    $closed = ticketFor($this->teacher, 'closed', [
        'resolution_due_at' => now()->subHour(),
        'closed_at' => now(),
    ]);
    expect($sla->posture($closed)['breached'])->toBeFalse();
});

it('treats an answered ticket as meeting its response deadline', function () {
    $sla = app(SlaCalculator::class);

    $ticket = ticketFor($this->teacher, 'in-progress', [
        'response_due_at' => now()->subHours(3),
        'first_response_at' => now()->subHours(4),
    ]);

    expect($sla->posture($ticket)['response_breached'])->toBeFalse();
});
