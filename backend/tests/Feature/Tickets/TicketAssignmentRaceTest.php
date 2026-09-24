<?php

declare(strict_types=1);

use App\Domains\Tickets\Actions\AssignTicket;
use App\Domains\Tickets\Exceptions\AssignmentConflictException;
use App\Enums\AssignmentStatus;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * FR-ASN-002 — **one active assignment per ticket**, and what happens to the
 * administrator who loses the race (Phase 2.6 plan, WP-E).
 *
 * The rule is enforced by the database, not by application code: the partial
 * unique index `technician_assignments_one_active_per_ticket` covers
 * `ticket_id` where the status is one of the four active states. Two
 * administrators assigning the same ticket at the same moment both pass their
 * `activeAssignment()` read — there is nothing to find yet — and only one
 * insert can survive.
 *
 * Testing this needs the interleaving, not just the outcome. A competing row
 * inserted *before* the Action runs is not a race at all: `AssignTicket` would
 * see it and treat the call as an ordinary reassignment. So the competitor is
 * injected from a `creating` hook — the instant between the Action's read and
 * its own insert, which is exactly the window the index exists to close.
 */
beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->rival = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->ticket = ticketFor($this->teacher);
});

/**
 * Insert a competing *active* assignment the way a parallel transaction would —
 * raw, so it does not re-enter the model events we are hooked into.
 */
function injectRivalAssignment(Ticket $ticket, int $technicianId, int $assignedBy): void
{
    DB::table('technician_assignments')->insert([
        'ticket_id' => $ticket->getKey(),
        'technician_id' => $technicianId,
        'assigned_by' => $assignedBy,
        'status' => AssignmentStatus::Pending->value,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('answers 409 when another administrator wins the assignment race', function () {
    $fired = false;

    // The competitor lands after this request read the ticket and before its own
    // insert — the precise interleaving the partial unique index guards.
    TechnicianAssignment::creating(function () use (&$fired) {
        if ($fired) {
            return;
        }
        $fired = true;
        injectRivalAssignment($this->ticket, $this->otherTechnician->id, $this->rival->id);
    });

    $response = $this->actingAs($this->admin)->postJson(
        "/api/admin/tickets/{$this->ticket->uuid}/assign",
        ['technician' => $this->technician->uuid],
    );

    // 409, not 422 and not 500: the request was well-formed and the caller was
    // entitled to make it — they simply lost.
    $response->assertStatus(409)
        ->assertJsonPath('code', 'assignment_conflict');

    expect($response->json('message'))->toContain('assigned this ticket');
});

it('does not persist the assignment of the administrator who lost', function () {
    $fired = false;

    TechnicianAssignment::creating(function () use (&$fired) {
        if ($fired) {
            return;
        }
        $fired = true;
        injectRivalAssignment($this->ticket, $this->otherTechnician->id, $this->rival->id);
    });

    $this->actingAs($this->admin)->postJson(
        "/api/admin/tickets/{$this->ticket->uuid}/assign",
        ['technician' => $this->technician->uuid],
    )->assertStatus(409);

    // What this simulation can prove: the loser's row never lands. What it
    // cannot is that the winner's row survives — the competitor is injected
    // from inside the losing transaction, so Postgres rolls it back along with
    // everything else. A real rival commits on its own connection and is
    // unaffected. The surviving-winner half of the invariant is covered
    // directly, without the application in the way, by the database test below.
    expect(TechnicianAssignment::query()
        ->where('ticket_id', $this->ticket->id)
        ->where('technician_id', $this->technician->id)
        ->exists())->toBeFalse();
});

it('rolls the whole losing transaction back rather than half-applying it', function () {
    $fired = false;

    TechnicianAssignment::creating(function () use (&$fired) {
        if ($fired) {
            return;
        }
        $fired = true;
        injectRivalAssignment($this->ticket, $this->otherTechnician->id, $this->rival->id);
    });

    $before = $this->ticket->fresh();

    $this->actingAs($this->admin)->postJson(
        "/api/admin/tickets/{$this->ticket->uuid}/assign",
        ['technician' => $this->technician->uuid],
    )->assertStatus(409);

    $after = $this->ticket->fresh();

    // A half-applied assignment is worse than none: the ticket must not point at
    // the loser, and no ticket_update may claim the assignment happened.
    expect($after->assigned_technician_id)->not->toBe($this->technician->id)
        ->and($after->current_status_id)->toBe($before->current_status_id);

    $claims = DB::table('ticket_updates')
        ->where('ticket_id', $this->ticket->id)
        ->where('update_type', 'assignment')
        ->count();

    expect($claims)->toBe(0);
});

it('surfaces the conflict as a typed exception from the action itself', function () {
    $fired = false;

    TechnicianAssignment::creating(function () use (&$fired) {
        if ($fired) {
            return;
        }
        $fired = true;
        injectRivalAssignment($this->ticket, $this->otherTechnician->id, $this->rival->id);
    });

    $action = app(AssignTicket::class);

    // Not only at the HTTP edge — a job or command calling the action directly
    // must get the same typed answer rather than a raw QueryException.
    expect(fn () => $action->handle(
        $this->ticket,
        $this->technician,
        $this->admin,
        request(),
    ))->toThrow(AssignmentConflictException::class);
});

it('lets the database refuse a second active assignment outright', function () {
    // The invariant without any application code in the way: if this ever stops
    // throwing, the index has been dropped and every guard above is decorative.
    injectRivalAssignment($this->ticket, $this->technician->id, $this->admin->id);

    expect(fn () => injectRivalAssignment(
        $this->ticket,
        $this->otherTechnician->id,
        $this->admin->id,
    ))->toThrow(QueryException::class);
});

it('permits a new assignment once the previous one is no longer active', function () {
    // The index is partial on purpose: a completed or declined assignment must
    // not block the next one, or a reassignment could never happen.
    injectRivalAssignment($this->ticket, $this->technician->id, $this->admin->id);

    DB::table('technician_assignments')
        ->where('ticket_id', $this->ticket->id)
        ->update(['status' => AssignmentStatus::Completed->value]);

    $this->actingAs($this->admin)->postJson(
        "/api/admin/tickets/{$this->ticket->uuid}/assign",
        ['technician' => $this->otherTechnician->uuid],
    )->assertStatus(200);

    expect(TechnicianAssignment::query()->where('ticket_id', $this->ticket->id)->count())->toBe(2);
});

afterEach(function () {
    // Model event listeners are global; leaving one registered would poison
    // every test that runs after this file.
    TechnicianAssignment::flushEventListeners();
});
