<?php

declare(strict_types=1);

use App\Domains\Analytics\Services\TicketMetrics;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * `GET /api/tickets/mine?resolved_within=N` — the exact list behind the teacher
 * dashboard's "Resolved (30 days)" figure (Phase 2.9).
 *
 * Three things have to hold, and each is pinned on its own:
 *
 *   1. The filter means what the dashboard figure means — same operator, same
 *      clock, same reporter scoping. The parity tests compare each figure with
 *      the `total` of the list it links to, over one fixture that holds a ticket
 *      in every state, so the number a teacher clicks and the list they land on
 *      cannot disagree.
 *   2. It narrows the requester's *own* list and nothing else: it cannot reach
 *      another reporter's ticket, and the feed and the administrator directory
 *      ignore it.
 *   3. Its bounds are validated. An out-of-range or non-numeric value is a 422,
 *      never a silently unfiltered list under a "resolved in 30 days" heading.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');
});

/**
 * The ticket uuids `/tickets/mine` returned for a query string, sorted so a test
 * asserts membership and not list order.
 *
 * @return list<string>
 */
function resolvedWithinIds(User $user, string $query): array
{
    /** @var list<string> $ids */
    $ids = test()->actingAs($user)->getJson("/api/tickets/mine?{$query}")->assertOk()->json('data.*.id');

    sort($ids);

    return $ids;
}

/**
 * One reporter's tickets in every state the lifecycle can leave them in, stamped
 * the way it stamps them: `resolved_at` on a move to `resolved`, `closed_at` on a
 * move to `closed`, and both nulled when a ticket goes back to `open`.
 *
 * @return array<string, Ticket>
 */
function resolvedWithinFixtures(User $reporter): array
{
    return [
        'open' => ticketFor($reporter, 'open'),
        'assigned' => ticketFor($reporter, 'assigned'),
        'in-progress' => ticketFor($reporter, 'in-progress'),
        'on-hold' => ticketFor($reporter, 'on-hold'),
        'resolved-5d' => ticketFor($reporter, 'resolved', ['resolved_at' => now()->subDays(5)]),
        'resolved-45d' => ticketFor($reporter, 'resolved', ['resolved_at' => now()->subDays(45)]),
        'closed-20d' => ticketFor($reporter, 'closed', [
            'resolved_at' => now()->subDays(21),
            'closed_at' => now()->subDays(20),
        ]),
        'closed-60d' => ticketFor($reporter, 'closed', [
            'resolved_at' => now()->subDays(61),
            'closed_at' => now()->subDays(60),
        ]),
        'cancelled' => ticketFor($reporter, 'cancelled'),
        // Resolved, then reopened: going back to `open` nulls `resolved_at`.
        'reopened' => ticketFor($reporter, 'open', ['resolved_at' => null, 'reopened_at' => now()->subDays(2)]),
    ];
}

/* ------------------------------------------------------------------- filter */

it('lists what was resolved inside the window and nothing older', function () {
    $recent = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(10)]);
    ticketFor($this->teacher, 'closed', [
        'resolved_at' => now()->subDays(40),
        'closed_at' => now()->subDays(39),
    ]);

    expect(resolvedWithinIds($this->teacher, 'resolved_within=30'))->toBe([$recent->uuid]);
});

it('leaves out a ticket that was reopened, and one that was never resolved', function () {
    ticketFor($this->teacher, 'open');
    ticketFor($this->teacher, 'open', ['resolved_at' => null, 'reopened_at' => now()->subDay()]);
    $resolved = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDay()]);

    expect(resolvedWithinIds($this->teacher, 'resolved_within=30'))->toBe([$resolved->uuid]);
});

it('counts the edge of the window the way the dashboard figure does: inclusively', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00'));

    $onTheEdge = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(30)]);
    ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(30)->subSecond()]);

    // The list and the figure it backs read the same edge: both keep the ticket
    // that is exactly 30 days old, and neither keeps the one a second older. The
    // mid-window parity fixtures below cannot tell `>=` from `>`, so this is the
    // one place a change to either operator alone is caught.
    expect(resolvedWithinIds($this->teacher, 'resolved_within=30'))->toBe([$onTheEdge->uuid])
        ->and(app(TicketMetrics::class)->reporterSummary($this->teacher)['resolved_recently'])->toBe(1);
});

it('honours the window it is given, not a fixed thirty days', function () {
    $threeDaysAgo = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(3)]);
    $tenDaysAgo = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(10)]);

    expect(resolvedWithinIds($this->teacher, 'resolved_within=7'))->toBe([$threeDaysAgo->uuid])
        ->and(resolvedWithinIds($this->teacher, 'resolved_within=14'))
        ->toBe(collect([$threeDaysAgo, $tenDaysAgo])->pluck('uuid')->sort()->values()->all());
});

it('narrows by status as well, which is how the dashboard link asks for it', function () {
    $fixtures = resolvedWithinFixtures($this->teacher);

    $expected = collect([$fixtures['resolved-5d'], $fixtures['closed-20d']])->pluck('uuid')->sort()->values()->all();

    expect(resolvedWithinIds($this->teacher, 'status=resolved,closed&resolved_within=30'))->toBe($expected);
});

it('accepts both ends of the allowed range', function () {
    $this->actingAs($this->teacher)->getJson('/api/tickets/mine?resolved_within=1')->assertOk();
    $this->actingAs($this->teacher)->getJson('/api/tickets/mine?resolved_within=365')->assertOk();
});

/* -------------------------------------------------------------- validation */

it('rejects a window that is out of range or not a whole number', function (string $value) {
    $this->actingAs($this->teacher)->getJson("/api/tickets/mine?resolved_within={$value}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('resolved_within');
})->with([
    'zero' => '0',
    'a year and a day' => '366',
    'text' => 'abc',
    'a fraction' => '1.5',
]);

/* ----------------------------------------------------------------- scoping */

it('never reaches another reporter’s ticket, by list or by uuid', function () {
    // The card shows a 200-character excerpt by design, so the marker sits
    // *beyond* that boundary — the same technique as TicketApiAuthorizationTest.
    $theirs = ticketFor($this->otherTeacher, 'resolved', [
        'resolved_at' => now()->subDays(3),
        'description' => str_repeat('Routine detail about the fault. ', 12).'RESOLVEDWITHINPRIVATE',
    ]);
    $mine = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(3)]);

    // Absent from the list, whatever window is asked for…
    expect(resolvedWithinIds($this->teacher, 'resolved_within=30'))->toBe([$mine->uuid])
        ->and(resolvedWithinIds($this->teacher, 'resolved_within=365'))->toBe([$mine->uuid]);

    // …and the uuid grants nothing the list withheld. A teacher may open another
    // teacher's ticket only as the community card (DD-40) — never the full record,
    // and never its lifecycle stamps. Asserted on the encoded payload, so a field
    // cannot leak back in through a later resource change.
    $card = $this->actingAs($this->teacher)->getJson("/api/tickets/{$theirs->uuid}")->assertOk();
    $body = json_encode($card->json());

    expect($body)->not->toContain('RESOLVEDWITHINPRIVATE')
        ->and($body)->not->toContain('resolved_at')
        ->and($body)->not->toContain('"description"');

    // A technician with no assignment has no list entry and no route to it at all.
    expect(resolvedWithinIds($this->technician, 'resolved_within=30'))->toBe([]);
    $this->actingAs($this->technician)->getJson("/api/tickets/{$theirs->uuid}")->assertForbidden();
});

it('gives the community feed and the administrator directory no new filter', function () {
    $old = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(60)]);

    $feed = $this->actingAs($this->teacher)->getJson('/api/tickets/feed?resolved_within=30')
        ->assertOk()->json('data.*.id');

    $directory = $this->actingAs($this->admin)->getJson('/api/admin/tickets?resolved_within=30')
        ->assertOk()->json('data.*.id');

    expect($feed)->toContain($old->uuid)
        ->and($directory)->toContain($old->uuid);
});

/* ------------------------------------------------------------------ parity */

/*
 * Each row is a dashboard figure, the exact query string the My tickets page
 * sends for the view that figure links to (frontend/src/features/tickets/
 * myTicketViews.ts), the key it is read from in `reporterSummary()`, and the
 * count the shared fixture should produce. The expected count is asserted on
 * BOTH sides so a figure and its list that are wrong in the same way still fail.
 */
it('keeps each dashboard figure and the list it links to in step', function (string $query, string $figure, int $expected) {
    resolvedWithinFixtures($this->teacher);
    // Another reporter's tickets, one in every state, must move neither side.
    resolvedWithinFixtures($this->otherTeacher);

    $summary = app(TicketMetrics::class)->reporterSummary($this->teacher);

    $total = $this->actingAs($this->teacher)->getJson("/api/tickets/mine?{$query}")
        ->assertOk()->json('meta.total');

    expect($summary[$figure])->toBe($expected)
        ->and($total)->toBe($expected);
})->with([
    'Open' => ['status=open,assigned,in-progress,on-hold,resolved', 'open', 7],
    'Awaiting your confirmation' => ['status=resolved', 'awaiting_confirmation', 2],
    'Resolved (30 days)' => ['status=resolved,closed&resolved_within=30', 'resolved_recently', 2],
]);

/*
 * The case the fixtures above cannot show, because they stamp rows by hand: a
 * ticket resolved and then sent back to work. Before the lifecycle cleared
 * `resolved_at` on that move, the figures read 1 and 1 here while both lists
 * read 0 — a teacher clicking "Awaiting your confirmation: 1" landed on an
 * empty page. Driven through the real lifecycle, since the stamp is what is
 * under test.
 */
it('keeps the figures and their lists in step for a ticket sent back to work', function () {
    $this->seed(SystemSettingSeeder::class);
    $lifecycle = app(TicketLifecycle::class);
    $metrics = app(TicketMetrics::class);

    $totalOf = fn (string $query): int => $this->actingAs($this->teacher)
        ->getJson("/api/tickets/mine?{$query}")->assertOk()->json('meta.total');

    $ticket = ticketFor($this->teacher, 'in-progress');
    $lifecycle->transition($ticket, ticketStatus('resolved'), $this->admin);

    // Resolved and waiting for the teacher: both sides say one.
    expect($metrics->reporterSummary($this->teacher)['awaiting_confirmation'])->toBe(1)
        ->and($totalOf('status=resolved'))->toBe(1);

    $lifecycle->transition($ticket->refresh(), ticketStatus('in-progress'), $this->admin);

    // Back in progress: nothing is awaiting confirmation or resolved, on either side.
    $summary = $metrics->reporterSummary($this->teacher);

    expect($summary['awaiting_confirmation'])->toBe(0)
        ->and($summary['resolved_recently'])->toBe(0)
        ->and($totalOf('status=resolved'))->toBe(0)
        ->and($totalOf('status=resolved,closed&resolved_within=30'))->toBe(0);
});
