<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Models\Ticket;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * Authorization for the technician queue's dashboard views (SDD DD-40, DD-81):
 * `scope=assigned`, `due_soon` and `breached`.
 *
 * The rule under test is the one CLAUDE.md §6 states for row-scoped modules: a
 * list filter without the matching single-record check is an IDOR. So for every
 * view, every row it returns must be readable by uuid, and a record absent from
 * the technician's views must be equally unreachable by uuid.
 */

/** The three views, as the frontend requests them. */
dataset('queue views', [
    'active queue' => 'scope=active',
    'open' => 'scope=assigned&status=open,assigned,in-progress,on-hold,resolved',
    'due soon' => 'scope=assigned&due_soon=1',
    'overdue' => 'scope=assigned&breached=1',
]);

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->teacher = userWithRole('teacher');
    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->colleague = userWithRole('technician');

    $sla = [
        'resolution_due_at' => now()->subHour(),
        'response_due_at' => now()->subHours(2),
        'first_response_at' => null,
        'resolved_at' => null,
    ];

    // Mine: overdue, and (through the same clock) in every view above.
    $this->mine = ticketFor($this->teacher, 'in-progress', ['assigned_technician_id' => $this->technician->id, ...$sla]);
    assign($this->mine, $this->technician, AssignmentStatus::InProgress);

    $this->dueSoon = ticketFor($this->teacher, 'assigned', [
        'assigned_technician_id' => $this->technician->id,
        ...$sla,
        'response_due_at' => null,
        'resolution_due_at' => now()->addHours(2),
    ]);
    assign($this->dueSoon, $this->technician, AssignmentStatus::Pending);

    // A colleague's ticket that is overdue, due soon and open — it matches every
    // filter and still must not appear for me.
    $this->theirs = ticketFor($this->teacher, 'in-progress', ['assigned_technician_id' => $this->colleague->id, ...$sla]);
    assign($this->theirs, $this->colleague, AssignmentStatus::InProgress);

    // An unassigned ticket matching the SLA filters: no assignment row for anyone.
    $this->unassigned = ticketFor($this->teacher, 'open', $sla);

    // Declined by me: assigned_technician_id is cleared on decline, and a declined
    // assignment grants no access at all (DD-42).
    $this->declined = ticketFor($this->teacher, 'open', $sla);
    assign($this->declined, $this->technician, AssignmentStatus::Declined);
});

/* ------------------------------------------------------- role by role */

it('refuses every queue view to a teacher', function (string $query) {
    $this->actingAs($this->teacher)->getJson("/api/tickets/assigned?{$query}")->assertForbidden();
})->with('queue views');

it('does not widen the administrator’s access either way', function (string $query) {
    // The queue is a technician surface; an administrator has no assigned
    // tickets of their own, so the owner-scoped views are empty for them.
    $response = $this->actingAs($this->admin)->getJson("/api/tickets/assigned?{$query}");

    if ($response->status() === 200) {
        expect($response->json('data'))->toBe([]);
    } else {
        $response->assertForbidden();
    }
})->with(['owner scoped' => ['scope=assigned&breached=1']]);

/* ----------------------------------------- list ↔ uuid equivalence */

it('never lists another technician’s, an unassigned or a declined ticket', function (string $query) {
    $listed = collect(
        $this->actingAs($this->technician)->getJson("/api/tickets/assigned?{$query}&per_page=100")
            ->assertOk()->json('data'),
    )->pluck('id');

    expect($listed)->not->toContain($this->theirs->uuid)
        ->not->toContain($this->unassigned->uuid)
        ->not->toContain($this->declined->uuid);
})->with('queue views');

it('returns 403 by uuid for every ticket absent from the views', function (Closure $absent) {
    $ticket = $absent($this);

    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")->assertForbidden();
    $this->actingAs($this->technician)->getJson("/api/tickets/{$ticket->uuid}")->assertForbidden();
})->with([
    'a colleague’s ticket' => [fn ($t) => $t->theirs],
    'an unassigned ticket' => [fn ($t) => $t->unassigned],
    'a ticket I declined' => [fn ($t) => $t->declined],
]);

it('lets the technician read every row each view returns, by uuid', function (string $query) {
    $rows = $this->actingAs($this->technician)->getJson("/api/tickets/assigned?{$query}&per_page=100")
        ->assertOk()->json('data');

    expect($rows)->not->toBe([]);

    foreach ($rows as $row) {
        $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$row['id']}")->assertOk();
    }
})->with('queue views');

it('returns no ticket without a readable assignment under scope=assigned', function () {
    // The owner column alone must not grant a row: strip the assignment and the
    // ticket leaves the view and becomes unreachable by uuid.
    $orphan = ticketFor($this->teacher, 'assigned', ['assigned_technician_id' => $this->technician->id]);

    $ids = fn () => collect(
        $this->actingAs($this->technician)->getJson('/api/tickets/assigned?scope=assigned&per_page=100')
            ->assertOk()->json('data'),
    )->pluck('id');

    expect($ids())->not->toContain($orphan->uuid);
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$orphan->uuid}")->assertForbidden();
});

/* ------------------------------------------------------------ validation */

it('rejects invalid parameters with 422', function (string $query, string $field) {
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown scope' => ['scope=everyone', 'scope'],
    'due_soon as text' => ['due_soon=soon', 'due_soon'],
    'due_soon as "true"' => ['due_soon=true', 'due_soon'],
    'breached as "true"' => ['breached=true', 'breached'],
    'unknown status' => ['scope=assigned&status=nonsense', 'status'],
]);

it('treats scope, due_soon and breached as no-ops outside the technician queue', function () {
    // The requester's own list ignores them: no new filter surface there.
    $own = $this->actingAs($this->teacher)->getJson('/api/tickets/mine?breached=1&due_soon=1&scope=assigned')
        ->assertOk()->json('data');

    $all = $this->actingAs($this->teacher)->getJson('/api/tickets/mine')->assertOk()->json('data');

    expect(collect($own)->pluck('id')->all())->toEqualCanonicalizing(collect($all)->pluck('id')->all());
    expect(Ticket::query()->count())->toBeGreaterThan(0);
});
