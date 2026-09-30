<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Models\Notification as NotificationRecord;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\TicketLookupSeeder;

/**
 * WP-2.7a — **the SLA sweep** (SRS FR-TKT-017, FR-NOT-003 T4; owner decision,
 * 2026-09-05).
 *
 * `SlaCalculator` computes breach **on read, never stored**, deliberately — the
 * posture on a screen is then always current instead of as stale as the last
 * sweep. Its own docblock names the one gap that design leaves: *"FR-TKT-017's
 * notification is the only part that needs a scheduled job."* Nobody is looking
 * at the screen at 3am, and a deadline that passes unobserved still passed.
 * `tickets:detect-sla` is that job, and this file is its specification.
 *
 * ── The two properties that matter ─────────────────────────────────────────
 *
 * **It finds what has actually crossed a threshold**, using the same calculator
 * the ticket directory and technician queue use, so the sweep and the screens
 * can never disagree.
 *
 * **It says each thing once.** The command runs hourly and re-detects the same
 * breach every hour until the ticket is resolved. What stops that becoming an
 * hourly nag is the notification's idempotency key — ticket plus stage, with no
 * date in it — so each of the four crossings is announced exactly once in a
 * ticket's life. That is asserted directly, because it is the property most
 * likely to be lost by a well-meaning change to the key.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

/** The SLA notifications written for one person, by stage. */
function slaStagesFor(User $user): array
{
    return NotificationRecord::query()
        ->where('user_id', $user->id)
        ->get()
        ->filter(fn (NotificationRecord $n): bool => ($n->data['topic'] ?? null) === 'ticket.sla_threshold')
        ->map(fn (NotificationRecord $n): mixed => $n->data['stage'])
        ->values()
        ->all();
}

/** A live ticket with deadlines placed relative to now. */
function ticketWithDeadlines(User $reporter, User $technician, array $deadlines): Ticket
{
    $ticket = ticketFor($reporter, 'in-progress', [
        'assigned_technician_id' => $technician->id,
        ...$deadlines,
    ]);

    assign($ticket, $technician, AssignmentStatus::Accepted);

    return $ticket;
}

it('tells the administrators and the assigned technician about a resolution breach', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'resolution_due_at' => now()->subHours(3),
        'first_response_at' => now()->subHours(6),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->admin))->toContain('resolution_breached')
        ->and(slaStagesFor($this->technician))->toContain('resolution_breached');
});

/**
 * DD-41 withholds SLA posture from a Teacher's ticket projection. Notifying the
 * reporter would disclose by another route exactly what the projection is
 * written to withhold — and at the moment the desk can least answer for it.
 */
it('never tells the reporter their ticket has breached', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'resolution_due_at' => now()->subHours(3),
        'first_response_at' => now()->subHours(6),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->teacher))->toBeEmpty();
});

it('warns before the resolution deadline, not only after it', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'resolution_due_at' => now()->addHours(2),
        'first_response_at' => now()->subHour(),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->technician))->toBe(['resolution_at_risk']);
});

/**
 * The response clock is the one `SlaCalculator::posture()` does not already
 * expose an at-risk answer for, so the command computes it — and the two clocks
 * are independent, which this asserts by breaching one while the other is only
 * approaching.
 */
it('treats the response clock and the resolution clock as independent', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'response_due_at' => now()->subHour(),
        'first_response_at' => null,
        'resolution_due_at' => now()->addHours(2),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->technician))
        ->toContain('response_breached')
        ->toContain('resolution_at_risk');
});

it('stops counting the response clock once somebody has answered', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'response_due_at' => now()->subHours(4),
        'first_response_at' => now()->subHours(5),
        'resolution_due_at' => now()->addDays(2),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->technician))->toBeEmpty();
});

it('does not report a breach against a ticket whose clock has stopped', function (): void {
    ticketFor($this->teacher, 'closed', [
        'assigned_technician_id' => $this->technician->id,
        'resolution_due_at' => now()->subWeek(),
        'resolved_at' => now()->subDays(6),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(NotificationRecord::query()->count())->toBe(0);
});

it('ignores a ticket that carries no deadlines at all', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'response_due_at' => null,
        'resolution_due_at' => null,
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(NotificationRecord::query()->count())->toBe(0);
});

/* ------------------------------------------------------------- the cadence */

/**
 * The property that makes an hourly sweep tolerable. The key is ticket plus
 * stage with **no date**, so re-detecting the same breach forever announces it
 * once.
 */
it('announces one crossing once, however often the sweep runs', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'resolution_due_at' => now()->subHours(3),
        'first_response_at' => now()->subHours(6),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();
    $this->artisan('tickets:detect-sla')->assertSuccessful();
    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->technician))->toBe(['resolution_breached'])
        ->and(slaStagesFor($this->admin))->toBe(['resolution_breached']);
});

/**
 * The other half of the same property: a ticket that was warned about and then
 * genuinely breaches must produce a *second*, different message. Announcing the
 * warning once must not mean staying silent about the breach.
 */
it('announces the breach after having announced the warning', function (): void {
    $ticket = ticketWithDeadlines($this->teacher, $this->technician, [
        'resolution_due_at' => now()->addHours(2),
        'first_response_at' => now()->subHour(),
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();
    expect(slaStagesFor($this->technician))->toBe(['resolution_at_risk']);

    // The deadline passes.
    $ticket->forceFill(['resolution_due_at' => now()->subHour()])->save();
    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->technician))->toBe(['resolution_at_risk', 'resolution_breached']);
});

it('honours an explicit lead time', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'resolution_due_at' => now()->addHours(10),
        'first_response_at' => now()->subHour(),
    ]);

    // Ten hours away is outside the four-hour default.
    $this->artisan('tickets:detect-sla')->assertSuccessful();
    expect(slaStagesFor($this->technician))->toBeEmpty();

    $this->artisan('tickets:detect-sla', ['--lead' => 24])->assertSuccessful();
    expect(slaStagesFor($this->technician))->toBe(['resolution_at_risk']);
});

it('reports what it found on the console', function (): void {
    ticketWithDeadlines($this->teacher, $this->technician, [
        'resolution_due_at' => now()->subHours(3),
        'first_response_at' => now()->subHours(6),
    ]);

    $this->artisan('tickets:detect-sla')
        ->expectsOutputToContain('SLA sweep: 1 breached, 0 at risk')
        ->assertSuccessful();
});

it('still tells the administrators when nobody is assigned', function (): void {
    ticketFor($this->teacher, 'open', [
        'response_due_at' => now()->subHour(),
        'first_response_at' => null,
    ]);

    $this->artisan('tickets:detect-sla')->assertSuccessful();

    expect(slaStagesFor($this->admin))->toBe(['response_breached']);
});
