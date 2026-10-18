<?php

declare(strict_types=1);

use App\Domains\Analytics\Services\AdminDashboardSignal;
use App\Domains\Tickets\Actions\UpdateTicket;
use App\Models\TicketCategory;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\Request;
use Tests\Support\ReportFixtures as F;

/*
 * Phase 2.10 — the Admin Dashboard's ticket overview (SDD DD-86/DD-88).
 *
 * One fixture, built so each rule has a known answer: a ticket at 23:59:59 and
 * one at 00:00:00 school time either side of a day boundary, a duplicate and a
 * cancelled ticket that "problems" must leave out, an archived room that must
 * still be counted and labelled, a ticket with no room, and a September
 * comparison cut at the same point in time as "now".
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->travelTo(F::local('2026-10-18 10:30:15'));

    $this->admin = userWithRole('administrator');
    $teacher = userWithRole('teacher');

    $floor = F::floor();
    $this->lab1 = F::room($floor, 'laboratory', ['name' => 'Lab 1']);
    $this->lab2 = F::room($floor, 'laboratory', ['name' => 'Lab 2']);
    $this->office = F::room($floor, 'office', ['name' => 'Registrar']);
    F::room($floor, 'storage', ['name' => 'Store']);

    $this->t1 = F::ticketAt($teacher, '2026-10-02 09:00', 'open', ['room_id' => $this->lab1->id]);
    F::ticketAt($teacher, '2026-10-05 14:00', 'assigned', ['room_id' => $this->lab1->id]);
    F::ticketAt($teacher, '2026-10-09 23:59:59', 'closed', ['room_id' => $this->office->id]);
    $this->t4 = F::ticketAt($teacher, '2026-10-10 00:00:00', 'resolved', ['room_id' => $this->lab2->id]);
    F::ticketAt($teacher, '2026-10-12 10:00', 'cancelled', ['room_id' => $this->lab1->id]);
    $this->duplicate = F::ticketAt($teacher, '2026-10-12 11:00', 'open', ['room_id' => $this->lab1->id, 'duplicate_of_id' => $this->t1->id]);
    F::ticketAt($teacher, '2026-10-15 08:00', 'in-progress', [
        'category_id' => TicketCategory::query()->where('slug', 'network')->value('id'),
    ]);

    // September, for the comparison: four before 18 Sep 10:31, one after.
    $this->s2 = F::ticketAt($teacher, '2026-09-10 09:00', 'closed');
    F::ticketAt($teacher, '2026-09-03 09:00', 'closed');
    F::ticketAt($teacher, '2026-09-17 09:00', 'closed');
    F::ticketAt($teacher, '2026-09-18 09:00', 'closed');
    F::ticketAt($teacher, '2026-09-18 12:00', 'closed');

    $this->lab2->delete();

    $this->overview = fn (string $query = ''): array => $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/ticket-overview'.($query !== '' ? '?'.$query : ''))
        ->assertOk()
        ->json();
});

it('counts the tickets reported this month, with duplicates and cancelled ones named', function (): void {
    $kpis = ($this->overview)()['data']['kpis'];

    expect($kpis['reported']['value'])->toBe(7)
        ->and($kpis['reported']['duplicates'])->toBe(1)
        ->and($kpis['reported']['cancelled'])->toBe(1)
        ->and($kpis['open_now']['value'])->toBe(5)
        ->and($kpis['open_now']['awaiting_confirmation'])->toBe(1)
        ->and($kpis['daily_average']['value'])->toBe(0.4)
        ->and($kpis['daily_average']['days'])->toBe(18);
});

it('compares with the previous month cut at the same moment, without a percentage on a base under 5', function (): void {
    $payload = ($this->overview)();
    $reported = $payload['data']['kpis']['reported'];

    expect($reported['comparison'])->toBe(4)
        ->and($reported['change'])->toBe(3)
        ->and($reported['direction'])->toBe('up')
        ->and($reported['change_pct'])->toBeNull()
        ->and($payload['meta']['comparison']['available'])->toBeTrue()
        ->and($payload['meta']['comparison']['range_label'])->toBe('1–18 Sep 2026')
        ->and($payload['data']['series']['stats']['comparison_total'])->toBe(4);
});

it('buckets by the school day: 23:59:59 and 00:00:00 land on different days', function (): void {
    $buckets = collect(($this->overview)()['data']['series']['buckets'])->keyBy('key');

    expect($buckets['2026-10-09']['count'])->toBe(1)
        ->and($buckets['2026-10-10']['count'])->toBe(1)
        ->and($buckets['2026-10-18']['in_progress'])->toBeTrue()
        ->and($buckets)->toHaveCount(18);
});

it('splits the status distribution into shares that add up to exactly 100.0', function (): void {
    $rows = collect(($this->overview)()['data']['status_distribution']['rows'])->keyBy('slug');

    expect($rows->keys()->all())->toBe(['open', 'assigned', 'in-progress', 'on-hold', 'resolved', 'closed', 'cancelled'])
        ->and($rows['open']['pct'])->toBe(28.5)
        ->and($rows['assigned']['pct'])->toBe(14.3)
        ->and($rows['on-hold']['count'])->toBe(0)
        ->and(round($rows->sum('pct'), 1))->toBe(100.0);
});

it('ranks rooms by problems — no duplicates, nothing cancelled — and keeps an archived room, labelled', function (): void {
    $locations = ($this->overview)()['data']['locations'];
    $ranking = collect($locations['ranking']);

    expect($ranking->pluck('room.name')->all())->toBe(['Lab 1', 'Lab 2', 'Registrar'])
        ->and($ranking->pluck('count')->all())->toBe([2, 1, 1])
        ->and($ranking->pluck('rank')->all())->toBe([1, 2, 2])
        ->and($ranking[1]['room']['archived'])->toBeTrue()
        ->and($locations['located'])->toBe(4);

    $types = collect($locations['by_type'])->keyBy('type');

    expect($types['laboratory']['count'])->toBe(3)
        ->and($types['laboratory']['rooms_total'])->toBe(2)
        ->and($types['laboratory']['per_room'])->toBe(1.5)
        ->and($types['office']['count'])->toBe(1)
        ->and($types['other']['count'])->toBe(0)
        ->and($types['other']['rooms_total'])->toBe(1)
        ->and($types['unspecified']['count'])->toBe(1);
});

it('counts "resolved in period" by when tickets moved to Resolved, whatever their report date', function (): void {
    F::history($this->t4, 'in-progress', 'resolved', '2026-10-11 09:00');
    F::history($this->s2, 'in-progress', 'resolved', '2026-10-03 09:00');
    // Resolved twice in the period, counted once.
    F::history($this->s2, 'resolved', 'in-progress', '2026-10-04 09:00');
    F::history($this->s2, 'in-progress', 'resolved', '2026-10-06 09:00');
    // Resolved before the period: not counted.
    F::history($this->t1, 'in-progress', 'resolved', '2026-09-28 09:00');

    expect(($this->overview)()['data']['kpis']['resolved_in_period']['value'])->toBe(2)
        // The status filter narrows the cohort, never this event count.
        ->and(($this->overview)('status=open')['data']['kpis']['resolved_in_period']['value'])->toBe(2);
});

it('stops comparing periods while a current-status filter is on', function (): void {
    $payload = ($this->overview)('status=open,assigned');

    expect($payload['data']['kpis']['reported']['value'])->toBe(3)
        ->and($payload['data']['kpis']['reported']['change'])->toBeNull()
        ->and($payload['data']['kpis']['reported']['direction'])->toBeNull()
        ->and($payload['data']['series']['comparison'])->toBeNull()
        ->and($payload['meta']['comparison']['available'])->toBeFalse()
        ->and($payload['meta']['comparison']['reason'])->toBe('status_filter')
        ->and(collect($payload['data']['summary']['sentences'])->firstWhere('key', 'comparison')['text'])->toStartWith('Not compared')
        ->and(collect($payload['data']['summary']['sentences'])->firstWhere('key', 'trend'))->toBeNull()
        ->and(collect($payload['data']['status_distribution']['rows'])->pluck('slug')->all())->toBe(['open', 'assigned']);
});

it('narrows by problem area and by room type', function (): void {
    expect(($this->overview)('problem_area=network')['data']['kpis']['reported']['value'])->toBe(1)
        ->and(($this->overview)('problem_area=hardware')['data']['kpis']['reported']['value'])->toBe(6)
        // Laboratories: Lab 1 (four tickets, with the duplicate and the cancelled one) and archived Lab 2.
        ->and(($this->overview)('room_type=laboratory')['data']['kpis']['reported']['value'])->toBe(5);
});

it('gives no daily average for a single day', function (): void {
    expect(($this->overview)('period=day')['data']['kpis']['daily_average'])->toBeNull();
});

it('compares last year by 52 weeks for a week, naming the ISO week', function (): void {
    $meta = ($this->overview)('period=week&compare=last_year')['meta'];

    expect($meta['comparison']['description'])->toBe('the same weeks last year (ISO week 42, 2025)');
});

it('says when records begin for a range that starts before them', function (): void {
    $sentences = collect(($this->overview)('period=year')['data']['summary']['sentences']);

    expect($sentences->firstWhere('key', 'records_start')['text'])->toContain('3 Sep 2026');
});

it('drops the cached overview when a ticket changes category or becomes a duplicate', function (): void {
    $signal = app(AdminDashboardSignal::class);
    $before = $signal->version();

    app(UpdateTicket::class)->handle($this->t4, ['category' => 'network'], $this->admin, Request::create('/'));
    app(UpdateTicket::class)->markDuplicate($this->t4, $this->t1, $this->admin, Request::create('/'));

    expect($signal->version())->toBe($before + 2);
});

/* -------------------------------------------------------------- validation */

it('rejects what it cannot honour with 422 on the offending parameter', function (string $query, string $field): void {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/ticket-overview?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'an unknown status' => ['status=nope', 'status'],
    'an unknown problem area' => ['problem_area=furniture', 'problem_area'],
    'a malformed room (422, not 500)' => ['room=not-a-uuid', 'room'],
    'a room that does not exist' => ['room=00000000-0000-4000-8000-000000000000', 'room'],
    'a future anchor' => ['period=month&anchor=2026-12-01', 'anchor'],
    'an inverted custom range' => ['period=custom&from=2026-10-10&to=2026-10-01', 'to'],
    'a comparison for "since a year"' => ['period=since_year&since_year=2025&compare=previous', 'compare'],
    'more day buckets than the cap' => ['period=year&granularity=day', 'granularity'],
    'an anchor on a rolling period' => ['period=last_30_days&anchor=2026-10-01', 'anchor'],
]);

it('rejects a room that is not in the selected building', function (): void {
    $elsewhere = F::room(F::floor());

    $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/ticket-overview?building='.$this->lab1->floor->building->uuid.'&room='.$elsewhere->uuid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room']);
});
