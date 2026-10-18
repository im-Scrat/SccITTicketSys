<?php

declare(strict_types=1);

use App\Models\TicketCategory;
use Database\Seeders\TicketLookupSeeder;
use Tests\Support\ReportFixtures as F;

/*
 * Phase 2.10 — the ticket directory understands the parameters a report's
 * drill-down carries (SDD DD-88), and only the administrator directory does.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->travelTo(F::local('2026-10-18 10:30'));

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');

    $floor = F::floor();
    $this->lab = F::room($floor, 'laboratory', ['name' => 'Lab 1']);
    $this->office = F::room($floor, 'office', ['name' => 'Registrar']);

    $this->late = F::ticketAt($this->teacher, '2026-10-09 23:59:59', 'open', ['room_id' => $this->lab->id]);
    $this->early = F::ticketAt($this->teacher, '2026-10-10 00:00:00', 'cancelled', ['room_id' => $this->office->id]);
    $this->roomless = F::ticketAt($this->teacher, '2026-10-11 09:00', 'open', [
        'category_id' => TicketCategory::query()->where('slug', 'network')->value('id'),
    ]);
    $this->duplicate = F::ticketAt($this->teacher, '2026-10-12 09:00', 'open', [
        'room_id' => $this->lab->id,
        'duplicate_of_id' => $this->late->id,
    ]);

    $this->list = fn (string $query): array => $this->actingAs($this->admin)
        ->getJson('/api/admin/tickets?per_page=100&'.$query)
        ->assertOk()
        ->json('data');
});

it('reads reported dates as school days, end date inclusive', function (): void {
    $ids = fn (string $query): array => collect(($this->list)($query))->pluck('id')->sort()->values()->all();

    expect($ids('reported_from=2026-10-10&reported_to=2026-10-10'))->toBe([$this->early->uuid])
        ->and($ids('reported_to=2026-10-09'))->toBe([$this->late->uuid]);
});

it('narrows by room type, by missing room and by problem area', function (): void {
    expect(collect(($this->list)('room_type=laboratory'))->pluck('id')->sort()->values()->all())
        ->toEqualCanonicalizing([$this->late->uuid, $this->duplicate->uuid])
        ->and(collect(($this->list)('without_room=1'))->pluck('id')->all())->toBe([$this->roomless->uuid])
        ->and(collect(($this->list)('problem_area=network'))->pluck('id')->all())->toBe([$this->roomless->uuid]);
});

it('leaves duplicates and cancelled tickets out when asked, which is what reports call problems', function (): void {
    expect(collect(($this->list)('exclude_duplicates=1&exclude_cancelled=1'))->pluck('id')->all())
        ->toEqualCanonicalizing([$this->late->uuid, $this->roomless->uuid]);
});

it('says where each row was reported, archived rooms included', function (): void {
    $this->office->delete();

    $row = collect(($this->list)('status=cancelled'))->first();

    expect($row['reported_location'])->toBe(['room' => 'Registrar', 'building' => $this->office->floor->building->name, 'archived' => true]);
});

it('rejects values outside each domain', function (string $query, string $field): void {
    $this->actingAs($this->admin)
        ->getJson('/api/admin/tickets?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'unknown room type' => ['room_type=garage', 'room_type'],
    'unknown problem area' => ['problem_area=furniture', 'problem_area'],
    'inverted range' => ['reported_from=2026-10-10&reported_to=2026-10-01', 'reported_to'],
    'not a date' => ['reported_from=10/01/2026', 'reported_from'],
]);

it('adds nothing to the requester list', function (): void {
    // `/tickets/mine` ignores report filters: the teacher still sees all four.
    $mine = $this->actingAs($this->teacher)
        ->getJson('/api/tickets/mine?per_page=100&room_type=office&without_room=1')
        ->assertOk()
        ->json('data');

    expect($mine)->toHaveCount(4);
});
