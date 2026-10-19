<?php

declare(strict_types=1);

use App\Models\Building;
use App\Models\TicketCategory;
use Database\Seeders\TicketLookupSeeder;
use Tests\Support\ReportFixtures as F;

/*
 * Phase 2.10 — a report figure and the list its drill-down opens are one
 * definition (SDD DD-88, extending DD-81).
 *
 * For every figure that offers a drill-down, open that link in the real ticket
 * directory and compare its total with the figure, across a matrix of filter
 * combinations. A figure that cannot be reproduced exactly must offer no link.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->travelTo(F::local('2026-10-18 10:30:15'));

    $this->admin = userWithRole('administrator');
    $teacher = userWithRole('teacher');

    $building = Building::factory()->create();
    $this->lab = F::room(F::floor($building, 1), 'laboratory');
    $this->office = F::room(F::floor($building, 2), 'office');
    $archived = F::room(F::floor($building, 3), 'server_room');
    $network = TicketCategory::query()->where('slug', 'network')->value('id');

    $first = F::ticketAt($teacher, '2026-10-01 00:00:00', 'open', ['room_id' => $this->lab->id]);
    F::ticketAt($teacher, '2026-10-03 13:00', 'assigned', ['room_id' => $this->lab->id, 'category_id' => $network]);
    F::ticketAt($teacher, '2026-10-06 08:00', 'closed', ['room_id' => $this->office->id]);
    F::ticketAt($teacher, '2026-10-06 09:00', 'cancelled', ['room_id' => $this->office->id]);
    F::ticketAt($teacher, '2026-10-08 17:00', 'open', ['room_id' => $this->lab->id, 'duplicate_of_id' => $first->id]);
    F::ticketAt($teacher, '2026-10-11 07:00', 'resolved', ['room_id' => $archived->id]);
    F::ticketAt($teacher, '2026-10-14 22:00', 'in-progress');
    F::ticketAt($teacher, '2026-10-18 10:00', 'on-hold', ['room_id' => $this->lab->id, 'category_id' => $network]);
    // Outside the month: never in any list below.
    F::ticketAt($teacher, '2026-09-30 23:59:59', 'open', ['room_id' => $this->lab->id]);

    $archived->delete();
});

dataset('filter matrix', [
    'no filters' => [''],
    'laboratories' => ['room_type=laboratory'],
    'one room' => ['room={lab}'],
    'two statuses' => ['status=open,assigned'],
    'category and problem area' => ['category=hardware,network&problem_area=network'],
    'a past week' => ['period=week&anchor=2026-10-05'],
    'grouped by week' => ['granularity=week'],
]);

it('opens every drill-down as exactly the tickets its figure counts', function (string $query): void {
    $query = str_replace('{lab}', $this->lab->uuid, $query);
    $data = $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/ticket-overview'.($query !== '' ? '?'.$query : ''))
        ->assertOk()
        ->json('data');

    $pairs = [['reported', $data['kpis']['reported']['value'], $data['kpis']['reported']['drilldown']]];
    $pairs[] = ['still open', $data['kpis']['open_now']['value'], $data['kpis']['open_now']['drilldown']];

    foreach ($data['status_distribution']['rows'] as $row) {
        $pairs[] = ['status '.$row['slug'], $row['count'], $row['drilldown']];
    }

    foreach ($data['series']['buckets'] as $bucket) {
        $pairs[] = ['bucket '.$bucket['key'], (int) $bucket['count'], $bucket['drilldown']];
    }

    foreach ($data['locations']['ranking'] as $row) {
        $pairs[] = ['room '.$row['room']['name'], $row['count'], $row['drilldown']];
    }

    foreach ($data['locations']['by_type'] as $row) {
        $pairs[] = ['type '.$row['type'], $row['count'], $row['drilldown']];
    }

    $checked = 0;

    foreach ($pairs as [$label, $figure, $drilldown]) {
        if ($drilldown === null) {
            continue;
        }

        expect($drilldown['path'])->toBe('/app/tickets/manage')
            ->and($drilldown['query']['tab'])->toBe('directory');

        $params = collect($drilldown['query'])->except('tab')->put('per_page', 1)->all();
        $total = $this->actingAs($this->admin)
            ->getJson('/api/admin/tickets?'.http_build_query($params))
            ->assertOk()
            ->json('meta.total');

        expect($total)->toBe($figure, "drill-down for {$label} lists {$total}, figure says {$figure}");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(3);
})->with('filter matrix');

it('offers no drill-down where the list cannot be reproduced exactly', function (): void {
    // Records must begin before September, or there is no comparison to show.
    F::ticketAt(userWithRole('teacher'), '2026-08-20 09:00', 'closed');

    $data = $this->actingAs($this->admin)
        ->getJson('/api/admin/reports/ticket-overview')
        ->assertOk()
        ->json('data');

    // An event count, not a list of reported tickets.
    expect($data['kpis']['resolved_in_period']['drilldown'])->toBeNull();

    // September is cut at "now" (18 Sep, 10:31): no date-only link can say that,
    // except for buckets that end at a midnight before the cut.
    $comparison = collect($data['series']['comparison']['buckets']);

    expect($comparison->last()['drilldown'])->toBeNull()
        ->and($comparison->first()['drilldown'])->not->toBeNull();
});
