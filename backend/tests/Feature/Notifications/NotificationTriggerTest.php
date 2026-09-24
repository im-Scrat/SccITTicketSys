<?php

declare(strict_types=1);

use App\Domains\Tickets\Actions\AssignTicket;
use App\Domains\Tickets\Actions\ManageTicketComment;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Models\Notification as NotificationRecord;
use App\Models\PcUnit;
use App\Models\QrCode;
use App\Models\WorkSupportRequest;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * WP-2.7a — **event → notification**, for every trigger the approved FR-NOT-003
 * matrix says this work package owns.
 *
 * Eight of the twelve triggers are implementable and wired here; two more (T3
 * comments, T4 SLA) were settled by the Client on 2026-09-05 and are wired too.
 * The remaining two — **T7 low-stock reorder** and **T8 procurement
 * approval/rejection** — depend on `consumables` and `procurement_requests`,
 * which WP-2.4b has not built, and are asserted absent rather than skipped.
 *
 * Each test exercises the **real write path**, not the listener: the point is
 * that assigning a ticket notifies somebody, not that a listener called a
 * dispatcher. A seam that is only tested from the listener down is a seam that
 * can be disconnected without a test noticing.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(MaintenanceTypeSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

/** Notifications addressed to one person, newest first. */
function inboxOf(App\Models\User $user): Illuminate\Support\Collection
{
    return NotificationRecord::query()->where('user_id', $user->id)->orderBy('id')->get();
}

function topicsFor(App\Models\User $user): array
{
    return inboxOf($user)->map(fn (NotificationRecord $n): mixed => $n->data['topic'])->all();
}

/* ------------------------------------------------------------------- T1 */

it('T1: tells a technician a ticket has been assigned to them', function (): void {
    $ticket = ticketFor($this->teacher);

    app(AssignTicket::class)->handle($ticket, $this->technician, $this->admin, Request::create('/'));

    expect(topicsFor($this->technician))->toContain('ticket.assigned')
        // The administrator who did it is not told they did it.
        ->and(inboxOf($this->admin))->toHaveCount(0);
});

it('T1: tells both technicians on a reassignment, with different messages', function (): void {
    $ticket = ticketFor($this->teacher);
    $assign = app(AssignTicket::class);

    $assign->handle($ticket, $this->technician, $this->admin, Request::create('/'));
    $assign->handle($ticket->refresh(), $this->otherTechnician, $this->admin, Request::create('/'));

    expect(topicsFor($this->otherTechnician))->toContain('ticket.assigned')
        ->and(topicsFor($this->technician))->toContain('ticket.reassigned');

    $unassigned = inboxOf($this->technician)->firstWhere('data.topic', 'ticket.reassigned');
    expect($unassigned->title)->toContain('no longer assigned to you');
});

/* ------------------------------------------------------------------- T2 */

it('T2: tells the reporter and the assignee about a status change, but not the actor', function (): void {
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    app(TicketLifecycle::class)->transition(
        $ticket,
        ticketStatus('in-progress'),
        $this->technician,
        null,
        Request::create('/'),
    );

    expect(topicsFor($this->teacher))->toContain('ticket.status_changed')
        // The technician moved it; they are not told about their own move.
        ->and(topicsFor($this->technician))->not->toContain('ticket.status_changed');
});

it('T2: tells both parties when the scheduled sweep closes a ticket with no actor', function (): void {
    $ticket = ticketFor($this->teacher, 'resolved', ['assigned_technician_id' => $this->technician->id]);

    app(TicketLifecycle::class)->transition($ticket, ticketStatus('closed'), null, null, Request::create('/'));

    expect(topicsFor($this->teacher))->toContain('ticket.status_changed')
        ->and(topicsFor($this->technician))->toContain('ticket.status_changed');
});

/* ------------------------------------------------------------------- T3 */

it('T3: tells the reporter and the assignee about a new comment', function (): void {
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    app(ManageTicketComment::class)->create(
        $ticket,
        'I have ordered the replacement part.',
        false,
        $this->technician,
        Request::create('/'),
    );

    expect(topicsFor($this->teacher))->toContain('ticket.commented')
        ->and(topicsFor($this->technician))->not->toContain('ticket.commented');
});

/**
 * The Client's chosen reading of "followed": demonstrated participation.
 */
it('T3: tells a prior commenter, which is what "followed" was decided to mean', function (): void {
    $ticket = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);
    $comments = app(ManageTicketComment::class);

    $comments->create($ticket, 'Any update on this?', false, $this->otherTechnician, Request::create('/'));
    $comments->create($ticket, 'Part ordered.', false, $this->technician, Request::create('/'));

    expect(topicsFor($this->otherTechnician))->toContain('ticket.commented');
});

/* ------------------------------------------------------------------- T5 */

it('T5: tells a technician when somebody else opens maintenance against them', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-SCHED-01', 'status' => PcStatus::Available->value]);

    $this->actingAs($this->admin)->postJson('/api/maintenance', [
        'title' => 'Quarterly clean',
        'type' => 'preventive',
        'pc_unit' => $pcUnit->uuid,
        'technician' => $this->technician->uuid,
        'scheduled_for' => now()->addWeek()->toIso8601String(),
    ])->assertCreated();

    expect(topicsFor($this->technician))->toContain('maintenance.scheduled')
        ->and(inboxOf($this->admin))->toHaveCount(0);
});

it('T5: does not tell a technician about a job they opened themselves', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-SCHED-02', 'status' => PcStatus::Available->value]);

    $this->actingAs($this->technician)->postJson('/api/maintenance', [
        'title' => 'Fixing my own job',
        'type' => 'corrective',
        'pc_unit' => $pcUnit->uuid,
        'scheduled_for' => now()->addDay()->toIso8601String(),
    ])->assertCreated();

    expect(inboxOf($this->technician))->toHaveCount(0);
});

it('T5: tells the technician and the administrators when a visit falls due', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-DUE-01', 'status' => PcStatus::Available->value]);
    maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::Scheduled->value,
        'scheduled_for' => now()->subWeek(),
    ]);

    $this->artisan('maintenance:detect-due')->assertSuccessful();

    expect(topicsFor($this->technician))->toContain('maintenance.due')
        ->and(topicsFor($this->admin))->toContain('maintenance.due');
});

/**
 * The sweep runs daily and re-derives the same overdue set every morning. One
 * reminder per record per day is the intended cadence; a reminder per run is
 * not.
 */
it('T5: does not repeat a due reminder when the sweep runs twice in a day', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-DUE-02', 'status' => PcStatus::Available->value]);
    maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::Scheduled->value,
        'scheduled_for' => now()->subWeek(),
    ]);

    $this->artisan('maintenance:detect-due')->assertSuccessful();
    $this->artisan('maintenance:detect-due')->assertSuccessful();

    expect(inboxOf($this->technician)->where('data.topic', 'maintenance.due'))->toHaveCount(1);
});

/* ------------------------------------------------------------------- T6 */

it('T6: tells a technician when their visit is moved to a different date', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-RESCH-01', 'status' => PcStatus::Available->value]);
    $record = maintenanceFor($this->technician, 'preventive', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::Scheduled->value,
        'scheduled_for' => now()->addWeek(),
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/maintenance/{$record->uuid}", [
            'scheduled_for' => now()->addWeeks(2)->toIso8601String(),
        ])->assertOk();

    expect(topicsFor($this->technician))->toContain('maintenance.rescheduled');
});

/* -------------------------------------------------------------- T9 / T10 */

it('T9 and T10: closes the FR-WSR-012 loop WP-2.6b carried forward', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-NOTIF-01', 'status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $pcUnit->id,
        'asset_id' => null,
        'code' => 'PCNOTIFY0001',
        'status' => QrStatus::Active->value,
    ]);
    maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    // T9 — the technician raises a request; the administrators are told.
    $this->actingAs($this->technician)
        ->postJson('/api/qr/PCNOTIFY0001/support-requests', [
            'explanation' => 'The power supply has failed and there is no spare on site.',
            'items' => [['description' => 'ATX power supply, 500W', 'quantity' => 1]],
        ])->assertCreated();

    expect(topicsFor($this->admin))->toContain('work_support.submitted')
        ->and(inboxOf($this->technician)->where('data.topic', 'work_support.submitted'))->toHaveCount(0);

    // T10 — the administrator answers; the submitting technician is told.
    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/work-support-requests/{$request->uuid}/approve", [
            'rescheduled_to' => now()->addWeek()->toIso8601String(),
        ])->assertOk();

    expect(topicsFor($this->technician))->toContain('work_support.decided')
        // Approving reschedules the linked visit, so T6 fires for the same person.
        ->and(topicsFor($this->technician))->toContain('maintenance.rescheduled');
});

it('T10: announces a clarification and the decision that follows it as two answers', function (): void {
    $pcUnit = PcUnit::factory()->create(['unit_code' => 'PC-NOTIF-02', 'status' => PcStatus::Available->value]);
    QrCode::factory()->create([
        'pc_unit_id' => $pcUnit->id,
        'asset_id' => null,
        'code' => 'PCNOTIFY0002',
        'status' => QrStatus::Active->value,
    ]);
    maintenanceFor($this->technician, 'corrective', [
        'pc_unit_id' => $pcUnit->id,
        'status' => MaintenanceStatus::InProgress->value,
    ]);

    $this->actingAs($this->technician)
        ->postJson('/api/qr/PCNOTIFY0002/support-requests', [
            'explanation' => 'A decision is needed on whether to replace or repair.',
            'items' => [['description' => 'Replacement mainboard', 'quantity' => 1]],
        ])->assertCreated();

    $request = WorkSupportRequest::query()->sole();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/work-support-requests/{$request->uuid}/request-clarification", [
            'clarification_reason' => 'Let us discuss the cost in person.',
        ])->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/work-support-requests/{$request->uuid}/decline", [
            'decline_reason' => 'The unit is due for replacement this term.',
        ])->assertOk();

    // Keyed on request + decision, so the second answer is not swallowed.
    expect(inboxOf($this->technician)->where('data.topic', 'work_support.decided'))->toHaveCount(2);
});

/* ------------------------------------------------------------------ T11 */

it('T11: tells a user their account was locked, and gives it an in-app half', function (): void {
    $user = userWithRole('teacher', ['email' => 'locked@sccit.local']);
    $attempts = (int) config('security.login.max_attempts', 5);

    for ($i = 0; $i < $attempts; $i++) {
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    expect(topicsFor($user))->toContain('account.locked');

    RateLimiter::clear('login|'.$user->email.'|127.0.0.1');
});

/* ------------------------------------------------- the two blocked triggers */

/**
 * FR-NOT-003 names twelve triggers; Phase 2.7 satisfies ten. This asserts the
 * honest gap rather than leaving it to a report nobody re-reads: no code path
 * can produce these, because the tables they would read do not have domains.
 */
it('does not claim the two triggers WP-2.4b blocks', function (): void {
    expect(NotificationRecord::query()->count())->toBe(0)
        ->and(class_exists('App\Domains\Inventory\Events\StockFellBelowReorderLevel'))->toBeFalse()
        ->and(class_exists('App\Domains\Procurement\Events\ProcurementRequestDecided'))->toBeFalse();
});
