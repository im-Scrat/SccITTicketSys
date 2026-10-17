<?php

declare(strict_types=1);

use App\Domains\Analytics\Services\TicketMetrics;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Enums\AssignmentStatus;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\Cache;

/*
 * A dashboard figure and the list it opens are one definition (SDD DD-81).
 *
 * Every card on the technician's "My work" widget is asserted against the exact
 * query the frontend sends when the card is clicked
 * (`frontend/src/features/tickets/assignedTicketViews.ts`) — by *set of tickets*,
 * not just by count, so two different sets that happen to be the same size cannot
 * pass. A second technician owns a full set of the same records throughout, so a
 * list that leaked across technicians would fail here too.
 *
 * Time is frozen on a whole second so the inclusive boundaries (`due exactly now`,
 * `due exactly now + 4 h`) are exact rather than flaky.
 */

/** The query the "Open tickets" card opens: every open-status ticket I own. */
const PARITY_OPEN_STATUSES = 'open,assigned,in-progress,on-hold,resolved';

/**
 * A ticket owned by $tech, with an assignment in the given state, and every SLA
 * column explicit — the factory's defaults must not decide a boundary.
 *
 * @param  array<string, mixed>  $sla
 */
function parityTicket(User $reporter, User $tech, string $status, AssignmentStatus $assignment, array $sla = []): Ticket
{
    $ticket = ticketFor($reporter, $status, [
        'assigned_technician_id' => $tech->id,
        'response_due_at' => null,
        'first_response_at' => null,
        'resolution_due_at' => null,
        'resolved_at' => null,
        ...$sla,
    ]);

    assign($ticket, $tech, $assignment);

    return $ticket;
}

/**
 * One technician's full spread of states. Returns uuids grouped by the card each
 * belongs to, so the expectations read as the plan's table does.
 *
 * @return array<string, list<string>>
 */
function parityFixture(User $reporter, User $tech): array
{
    $now = now();

    $dueSoon = parityTicket($reporter, $tech, 'assigned', AssignmentStatus::Pending, ['resolution_due_at' => $now->copy()->addHours(2)]);
    $dueEdge = parityTicket($reporter, $tech, 'in-progress', AssignmentStatus::InProgress, ['resolution_due_at' => $now->copy()->addHours(4)]);
    $dueNow = parityTicket($reporter, $tech, 'assigned', AssignmentStatus::Accepted, ['resolution_due_at' => $now->copy()]);
    $dueOutside = parityTicket($reporter, $tech, 'assigned', AssignmentStatus::Pending, ['resolution_due_at' => $now->copy()->addHours(4)->addSecond()]);

    $lateFix = parityTicket($reporter, $tech, 'in-progress', AssignmentStatus::InProgress, ['resolution_due_at' => $now->copy()->subHour()]);
    // Overdue by the response rule alone: nobody has answered, fix-by is far off.
    $lateReply = parityTicket($reporter, $tech, 'on-hold', AssignmentStatus::OnHold, [
        'response_due_at' => $now->copy()->subHours(2),
        'resolution_due_at' => $now->copy()->addDay(),
    ]);
    // Answered in time, so a past response deadline is not a breach.
    $answered = parityTicket($reporter, $tech, 'assigned', AssignmentStatus::Pending, [
        'response_due_at' => $now->copy()->subHours(2),
        'first_response_at' => $now->copy()->subHours(3),
        'resolution_due_at' => $now->copy()->addDays(2),
    ]);

    // Awaiting the reporter's confirmation: open, but the assignment is done.
    $resolved = parityTicket($reporter, $tech, 'resolved', AssignmentStatus::Completed, [
        'resolved_at' => $now->copy()->subHour(),
        'resolution_due_at' => $now->copy()->subHour(),
    ]);
    // Sent back to the queue by the reporter: open, assignment already ended.
    $reopened = parityTicket($reporter, $tech, 'open', AssignmentStatus::Completed, ['resolution_due_at' => $now->copy()->addDay()]);

    // Terminal tickets count toward nothing, whatever their clocks say.
    parityTicket($reporter, $tech, 'closed', AssignmentStatus::Completed, [
        'resolved_at' => $now->copy()->subDays(6),
        'resolution_due_at' => $now->copy()->subDays(5),
    ]);
    parityTicket($reporter, $tech, 'cancelled', AssignmentStatus::Cancelled, ['resolution_due_at' => $now->copy()->subDays(2)]);

    $uuids = fn (Ticket ...$tickets): array => array_map(static fn (Ticket $t): string => $t->uuid, $tickets);

    return [
        'active' => $uuids($dueSoon, $dueEdge, $dueNow, $dueOutside, $lateFix, $lateReply, $answered),
        'open' => $uuids($dueSoon, $dueEdge, $dueNow, $dueOutside, $lateFix, $lateReply, $answered, $resolved, $reopened),
        'due_soon' => $uuids($dueSoon, $dueEdge, $dueNow),
        'overdue' => $uuids($lateFix, $lateReply),
    ];
}

/** A card's value from the technician's "My work" widget. */
function parityCard(array $widgets, string $key): array
{
    $myWork = collect($widgets)->firstWhere('key', 'my-work');

    return collect($myWork['items'])->firstWhere('key', $key);
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    // Whole second, frozen: the boundaries below are exact.
    $this->travelTo(now()->startOfSecond());

    $this->teacher = userWithRole('teacher');
    $this->technician = userWithRole('technician');
    $this->colleague = userWithRole('technician');

    $this->mine = parityFixture($this->teacher, $this->technician);
    $this->theirs = parityFixture($this->teacher, $this->colleague);

    Cache::flush();

    $this->widgets = $this->actingAs($this->technician)
        ->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    /** The uuids a card's destination list returns, following every page. */
    $this->listed = function (string $query): array {
        $response = $this->actingAs($this->technician)
            ->getJson("/api/tickets/assigned?{$query}&per_page=100")->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    };
});

it('opens a list equal to each figure — same set, not just the same size', function (string $card, string $query, string $group) {
    $expected = $this->mine[$group];
    $listed = ($this->listed)($query);

    expect(parityCard($this->widgets, $card)['value'])->toBe(count($expected))
        ->and($listed)->toEqualCanonicalizing($expected)
        // Nothing of the colleague's, in any view.
        ->and(array_intersect($listed, $this->theirs['open']))->toBe([]);
})->with([
    'Active assignments' => ['active_assignments', 'scope=active', 'active'],
    'Open tickets' => ['open_tickets', 'scope=assigned&status='.PARITY_OPEN_STATUSES, 'open'],
    'Due within 4 h' => ['at_risk', 'scope=assigned&due_soon=1', 'due_soon'],
    'Overdue' => ['breached', 'scope=assigned&breached=1', 'overdue'],
]);

it('counts the inclusive boundaries on both sides', function () {
    $dueSoon = ($this->listed)('scope=assigned&due_soon=1');

    // Due exactly now and exactly now + 4 h are in; one second past the window is out.
    $edge = Ticket::query()->where('resolution_due_at', now()->addHours(4))->where('assigned_technician_id', $this->technician->id)->value('uuid');
    $now = Ticket::query()->where('resolution_due_at', now())->where('assigned_technician_id', $this->technician->id)->value('uuid');
    $outside = Ticket::query()->where('resolution_due_at', now()->addHours(4)->addSecond())->where('assigned_technician_id', $this->technician->id)->value('uuid');

    expect($dueSoon)->toContain($edge)->toContain($now)->not->toContain($outside);

    // A ticket due exactly now is due soon, not overdue (`< now`, not `<=`).
    expect(($this->listed)('scope=assigned&breached=1'))->not->toContain($now);

    // One second later it has crossed over.
    $this->travel(1)->seconds();
    expect(($this->listed)('scope=assigned&breached=1'))->toContain($now);
});

it('labels the breach card Overdue and keeps its key', function () {
    $card = parityCard($this->widgets, 'breached');

    expect($card['label'])->toBe('Overdue')
        ->and($card['value'])->toBe(2)
        ->and($card['tone'])->toBe('danger')
        ->and(parityCard($this->widgets, 'at_risk')['label'])->toBe('Due within 4 h');

    // The label and the window are the same constant.
    expect(SlaCalculator::AT_RISK_LEAD_HOURS)->toBe(4)
        ->and(app(TicketMetrics::class)->slaPosture(technician: $this->technician)['lead_hours'])->toBe(4);
});

it('carries a tone only while the figure is above zero', function () {
    $quiet = userWithRole('technician');
    Cache::flush();

    $widgets = $this->actingAs($quiet)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');

    foreach (['at_risk', 'breached'] as $key) {
        $card = parityCard($widgets, $key);

        expect($card['value'])->toBe(0)
            ->and($card['tone'] ?? null)->toBeNull();
    }
});

it('serves the same figures to the admin Service desk widget under the Overdue label', function () {
    $admin = userWithRole('administrator');
    Cache::flush();

    $widgets = $this->actingAs($admin)->getJson('/api/dashboard/widgets')->assertOk()->json('data.widgets');
    $desk = collect($widgets)->firstWhere('key', 'service-desk');
    $breached = collect($desk['items'])->firstWhere('key', 'breached');

    // 2 + 2: both technicians' overdue tickets.
    expect($breached['label'])->toBe('Overdue')
        ->and($breached['value'])->toBe(4);
});
