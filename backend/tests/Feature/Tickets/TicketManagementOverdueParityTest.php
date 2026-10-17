<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Models\Ticket;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * Admin Ticket Management: a tile's count and the list its click opens are the
 * same set (SDD DD-81).
 *
 * Two defects this pins. The "Overdue" tile counted a missed *response* as well
 * as a missed fix-by time, while the `breached` filter it opens tested the
 * fix-by time alone — so the tile and its list disagreed. And the tiles sent
 * `breached=true`: in a query string that is the *string* "true", which
 * Laravel's `boolean` rule refuses (it accepts true/false/1/0/"1"/"0"), so
 * clicking one returned 422.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->travelTo(now()->startOfSecond());

    $this->admin = userWithRole('administrator');
    $this->teacher = userWithRole('teacher');
    $this->technician = userWithRole('technician');

    $now = now();
    $make = fn (string $status, array $sla = []): Ticket => ticketFor($this->teacher, $status, [
        'response_due_at' => null,
        'first_response_at' => null,
        'resolution_due_at' => null,
        'resolved_at' => null,
        ...$sla,
    ]);

    $this->lateFix = $make('in-progress', ['resolution_due_at' => $now->copy()->subHour()]);
    // Overdue by the response rule only — the case the old filter missed.
    $this->lateReply = $make('open', ['response_due_at' => $now->copy()->subHours(2), 'resolution_due_at' => $now->copy()->addDay()]);
    $this->fine = $make('open', ['response_due_at' => $now->copy()->addHour(), 'resolution_due_at' => $now->copy()->addDay()]);
    $this->closedLate = $make('closed', ['resolution_due_at' => $now->copy()->subDays(3)]);
    $this->awaiting = $make('resolved', ['resolved_at' => $now->copy()->subHour()]);
    $this->awaitingToo = $make('resolved', ['resolved_at' => $now->copy()->subDays(2)]);

    assign($this->lateFix, $this->technician, AssignmentStatus::InProgress);
});

it('opens the Overdue tile as exactly the tickets it counts', function () {
    $summary = $this->actingAs($this->admin)->getJson('/api/admin/tickets/dashboard')
        ->assertOk()->json('data.summary');

    $listed = $this->actingAs($this->admin)->getJson('/api/admin/tickets?breached=1&per_page=100')
        ->assertOk()->json('data');

    expect($summary['breached'])->toBe(2)
        ->and(collect($listed)->pluck('id')->all())
        ->toEqualCanonicalizing([$this->lateFix->uuid, $this->lateReply->uuid]);
});

it('opens the Awaiting confirmation tile as exactly the tickets it counts', function () {
    $summary = $this->actingAs($this->admin)->getJson('/api/admin/tickets/dashboard')
        ->assertOk()->json('data.summary');

    $listed = $this->actingAs($this->admin)->getJson('/api/admin/tickets?awaiting_confirmation=1&per_page=100')
        ->assertOk()->json('data');

    expect($summary['awaiting_confirmation'])->toBe(2)
        ->and(collect($listed)->pluck('id')->all())
        ->toEqualCanonicalizing([$this->awaiting->uuid, $this->awaitingToo->uuid]);
});

it('refuses the string "true" for a boolean filter — the tiles must send 1', function (string $filter) {
    // The API contract is the normalised form. This is what the tiles used to
    // send, and why clicking them failed; the frontend now sends `1`.
    $this->actingAs($this->admin)->getJson("/api/admin/tickets?{$filter}=true")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($filter);
})->with(['breached', 'awaiting_confirmation']);

it('accepts 1 for both boolean filters', function (string $filter) {
    $this->actingAs($this->admin)->getJson("/api/admin/tickets?{$filter}=1")->assertOk();
})->with(['breached', 'awaiting_confirmation']);
