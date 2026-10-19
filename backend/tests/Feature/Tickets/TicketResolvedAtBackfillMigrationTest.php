<?php

declare(strict_types=1);

use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The repair half of the stale-`resolved_at` fix (SRS FR-TKT-006).
 *
 * The lifecycle test pins that new rework no longer leaves a stamp; this pins
 * that the rows it already left are cleaned up — and that nothing legitimate is
 * touched. Migrations run in production on a database that is in use, so the
 * cases that matter are the negative ones: a resolved ticket must keep its stamp
 * (it is what "awaiting confirmation" is read from), and so must a closed one.
 */

const RESOLVED_AT_BACKFILL = '2026_10_14_100000_clear_stale_resolved_at_on_reworked_tickets.php';

function resolvedAtBackfill(): object
{
    return require database_path('migrations/'.RESOLVED_AT_BACKFILL);
}

/** The stored `resolved_at` of a ticket, read raw so no cast or accessor is involved. */
function storedResolvedAt(int $ticketId): ?string
{
    return DB::table('tickets')->where('id', $ticketId)->value('resolved_at');
}

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);

    $this->teacher = userWithRole('teacher');
});

it('clears a stale resolved_at on every status that is not resolved or closed', function (string $status) {
    $ticket = ticketFor($this->teacher, $status, ['resolved_at' => now()->subDays(3)]);

    resolvedAtBackfill()->up();

    expect(storedResolvedAt($ticket->id))->toBeNull();
})->with(['open', 'assigned', 'in-progress', 'on-hold', 'cancelled']);

it('leaves a resolved ticket its stamp, which is what awaiting confirmation is read from', function () {
    $ticket = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDays(3)]);
    $before = storedResolvedAt($ticket->id);

    resolvedAtBackfill()->up();

    expect($before)->not->toBeNull()
        ->and(storedResolvedAt($ticket->id))->toBe($before);
});

it('leaves a closed ticket its stamp and its closing time', function () {
    $ticket = ticketFor($this->teacher, 'closed', [
        'resolved_at' => now()->subDays(5),
        'closed_at' => now()->subDays(4),
    ]);
    $before = DB::table('tickets')->where('id', $ticket->id)->first(['resolved_at', 'closed_at']);

    resolvedAtBackfill()->up();

    $after = DB::table('tickets')->where('id', $ticket->id)->first(['resolved_at', 'closed_at']);
    expect($after->resolved_at)->toBe($before->resolved_at)
        ->and($after->closed_at)->toBe($before->closed_at);
});

it('repairs an archived ticket too, and does not move updated_at', function () {
    $ticket = ticketFor($this->teacher, 'in-progress', [
        'resolved_at' => now()->subDays(3),
        'updated_at' => now()->subDays(2),
    ]);
    $ticket->delete();
    $updatedAt = DB::table('tickets')->where('id', $ticket->id)->value('updated_at');

    resolvedAtBackfill()->up();

    expect(storedResolvedAt($ticket->id))->toBeNull()
        // A repair is not an edit: moving updated_at would reorder every list sorted by it.
        ->and(DB::table('tickets')->where('id', $ticket->id)->value('updated_at'))->toBe($updatedAt);
});

it('is safe to run twice', function () {
    $stale = ticketFor($this->teacher, 'in-progress', ['resolved_at' => now()->subDay()]);
    $settled = ticketFor($this->teacher, 'resolved', ['resolved_at' => now()->subDay()]);

    resolvedAtBackfill()->up();
    $afterFirst = [storedResolvedAt($stale->id), storedResolvedAt($settled->id)];

    resolvedAtBackfill()->up();

    expect([storedResolvedAt($stale->id), storedResolvedAt($settled->id)])->toBe($afterFirst)
        ->and($afterFirst[0])->toBeNull()
        ->and($afterFirst[1])->not->toBeNull();
});
