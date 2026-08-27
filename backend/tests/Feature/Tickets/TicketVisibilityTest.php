<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Enums\AssignmentStatus;
use App\Models\Permission;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Database\Seeders\TicketLookupSeeder;

/*
 * The Phase 2.6 authorization contract (SRS FR-TKT-013; SDD DD-40).
 *
 * Unlike Locations and Assets, the ticket module is not closed by withholding a
 * permission — all three roles hold `tickets.view`. What separates them is which
 * *rows* they may reach, and this suite pins that:
 *
 *   Administrator  every ticket
 *   Technician     only tickets they hold an assignment for; never unrelated
 *                  ones; read outlives the assignment, write does not; a
 *                  declined assignment grants nothing
 *   Teacher        own tickets in full, everyone else's as a restricted card
 *
 * Crucially it also pins the *equivalence*: a ticket absent from a user's list
 * must be equally unreachable by uuid. That is the IDOR this design exists to
 * prevent.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->otherTechnician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');

    $this->visibility = app(TicketVisibility::class);
});

/* ------------------------------------------------------------ the levels */

it('gives an administrator the full projection of every ticket', function () {
    $ticket = ticketFor($this->otherTeacher);

    expect($this->visibility->levelFor($this->admin, $ticket))
        ->toBe(TicketVisibility::LEVEL_FULL)
        ->and($this->visibility->canWork($this->admin, $ticket))->toBeTrue();
});

it('gives a reporter the full projection of their own ticket', function () {
    $ticket = ticketFor($this->teacher);

    expect($this->visibility->levelFor($this->teacher, $ticket))
        ->toBe(TicketVisibility::LEVEL_FULL);
});

it('gives a teacher only the community projection of another reporter’s ticket', function () {
    $ticket = ticketFor($this->otherTeacher);

    expect($this->visibility->levelFor($this->teacher, $ticket))
        ->toBe(TicketVisibility::LEVEL_COMMUNITY)
        ->and($this->visibility->canSee($this->teacher, $ticket))->toBeTrue()
        ->and($this->visibility->canSeeFull($this->teacher, $ticket))->toBeFalse()
        // A requester never reads internal notes, on any ticket including theirs.
        ->and($this->visibility->canSeeInternal($this->teacher, $ticket))->toBeFalse();
});

it('gives a technician nothing on a ticket they were never assigned', function () {
    $ticket = ticketFor($this->teacher);

    expect($this->visibility->levelFor($this->technician, $ticket))
        ->toBe(TicketVisibility::LEVEL_NONE)
        ->and($this->visibility->canSee($this->technician, $ticket))->toBeFalse()
        ->and($this->visibility->canWork($this->technician, $ticket))->toBeFalse();
});

it('holds tickets.view alone worthless to a technician', function () {
    $ticket = ticketFor($this->teacher);

    // The permission is present; the assignment is not. The permission grants
    // nothing on its own — that is the whole point of DD-40.
    expect($this->technician->hasPermissionTo('tickets.view'))->toBeTrue()
        ->and($this->visibility->canSee($this->technician, $ticket))->toBeFalse();
});

/* --------------------------------------------- technician read/write split */

it('lets an assigned technician both read and work the ticket', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    expect($this->visibility->canSeeFull($this->technician, $ticket))->toBeTrue()
        ->and($this->visibility->canWork($this->technician, $ticket))->toBeTrue()
        ->and($this->visibility->canSeeInternal($this->technician, $ticket))->toBeTrue();
});

it('keeps a completed assignment readable but no longer writable', function () {
    $ticket = ticketFor($this->teacher, 'resolved');
    assign($ticket, $this->technician, AssignmentStatus::Completed);

    // Work history, repair evidence and audit reference survive completion…
    expect($this->visibility->canSeeFull($this->technician, $ticket))->toBeTrue()
        ->and($this->visibility->canSeeInternal($this->technician, $ticket))->toBeTrue()
        // …but every write ability lapses with the assignment.
        ->and($this->visibility->canWork($this->technician, $ticket))->toBeFalse();
});

it('revokes all access when a technician declined the assignment', function () {
    $ticket = ticketFor($this->teacher);
    assign($ticket, $this->technician, AssignmentStatus::Declined);

    // Declining is an explicit refusal of the work, so there is no work history
    // to reference and nothing to retain.
    expect($this->visibility->levelFor($this->technician, $ticket))
        ->toBe(TicketVisibility::LEVEL_NONE)
        ->and($this->visibility->canWork($this->technician, $ticket))->toBeFalse();
});

it('keeps a reassigned technician’s read access to work they did', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    assign($ticket, $this->technician, AssignmentStatus::Reassigned);

    expect($this->visibility->canSeeFull($this->technician, $ticket))->toBeTrue()
        ->and($this->visibility->canWork($this->technician, $ticket))->toBeFalse();
});

it('never leaks one technician’s ticket to another', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    assign($ticket, $this->technician, AssignmentStatus::InProgress);

    expect($this->visibility->canSee($this->otherTechnician, $ticket))->toBeFalse();
});

/* ------------------------------------------------------------- the feed */

it('closes the requester community feed to technicians', function () {
    expect($this->visibility->canSeeFeed($this->teacher))->toBeTrue()
        ->and($this->visibility->canSeeFeed($this->admin))->toBeTrue()
        // Their surface is the assigned queue, not the requester community.
        ->and($this->visibility->canSeeFeed($this->technician))->toBeFalse();
});

/* ------------------------------------------------- list / detail equivalence */

it('scopes a list identically to how it scopes a single record', function () {
    $mine = ticketFor($this->teacher);
    $theirs = ticketFor($this->otherTeacher);
    $assigned = ticketFor($this->otherTeacher, 'assigned');
    assign($assigned, $this->technician, AssignmentStatus::Accepted);

    $visibleToTechnician = $this->visibility
        ->scope(Ticket::query(), $this->technician)
        ->pluck('id');

    // Every row the list returns is one canSee() agrees with…
    foreach ($visibleToTechnician as $id) {
        expect($this->visibility->canSee($this->technician, Ticket::find($id)))->toBeTrue();
    }

    // …and every row it withholds is one canSee() refuses. This equivalence is
    // what stops a uuid becoming a bypass.
    foreach ([$mine, $theirs] as $hidden) {
        expect($visibleToTechnician)->not->toContain($hidden->id)
            ->and($this->visibility->canSee($this->technician, $hidden))->toBeFalse();
    }

    expect($visibleToTechnician)->toContain($assigned->id);
});

it('returns no rows at all to a user without the permission', function () {
    ticketFor($this->teacher);

    $stranger = userWithRole('teacher');
    $permission = Permission::query()->where('name', 'tickets.view')->firstOrFail();
    $stranger->directPermissions()->attach($permission->id, ['grant_type' => 'deny']);
    app(PermissionResolver::class)->forget($stranger);

    expect($this->visibility->scope(Ticket::query(), $stranger)->count())->toBe(0)
        ->and($this->visibility->canSeeFeed($stranger))->toBeFalse();
});

/* ----------------------------------------------------- internal comments */

it('never exposes an internal comment to a requester', function () {
    $ticket = ticketFor($this->teacher);

    $internal = TicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $this->admin->id,
        'is_internal' => true,
    ]);
    $public = TicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $this->teacher->id,
        'is_internal' => false,
    ]);

    // Even the reporter of the ticket cannot read the internal note on it.
    expect($this->teacher->can('view', $internal))->toBeFalse()
        ->and($this->teacher->can('view', $public))->toBeTrue()
        ->and($this->admin->can('view', $internal))->toBeTrue();
});

it('lets an assigned technician read internal notes but not an unassigned one', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    assign($ticket, $this->technician, AssignmentStatus::Accepted);

    $internal = TicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $this->admin->id,
        'is_internal' => true,
    ]);

    expect($this->technician->can('view', $internal))->toBeTrue()
        ->and($this->otherTechnician->can('view', $internal))->toBeFalse();
});

/* ----------------------------------------------------- policy delegation */

it('routes every policy read ability through the visibility service', function () {
    $ticket = ticketFor($this->otherTeacher);

    // The policy must agree with the service, because they are the same answer.
    expect($this->teacher->can('view', $ticket))
        ->toBe($this->visibility->canSee($this->teacher, $ticket))
        ->and($this->teacher->can('viewFull', $ticket))
        ->toBe($this->visibility->canSeeFull($this->teacher, $ticket))
        ->and($this->technician->can('view', $ticket))
        ->toBe($this->visibility->canSee($this->technician, $ticket));
});

it('keeps the administrative surface administrator-only', function () {
    expect($this->admin->can('viewAdministrative', Ticket::class))->toBeTrue()
        ->and($this->technician->can('viewAdministrative', Ticket::class))->toBeFalse()
        ->and($this->teacher->can('viewAdministrative', Ticket::class))->toBeFalse();
});

it('withdraws assignment and export from the technician baseline', function () {
    expect($this->technician->hasPermissionTo('tickets.assign'))->toBeFalse()
        ->and($this->technician->hasPermissionTo('tickets.export'))->toBeFalse()
        ->and($this->admin->hasPermissionTo('tickets.assign'))->toBeTrue();
});

it('still allows a deputized technician to assign', function () {
    // FR-ASN-001's "(and permitted Technicians)" — the narrow, documented
    // exception. A per-user grant opens it without widening the role.
    $lead = userWithRole('technician');
    $permission = Permission::query()->where('name', 'tickets.assign')->firstOrFail();
    $lead->directPermissions()->attach($permission->id, ['grant_type' => 'grant']);
    app(PermissionResolver::class)->forget($lead);

    $ticket = ticketFor($this->teacher);

    expect($lead->can('assign', $ticket))->toBeTrue()
        ->and($this->technician->can('assign', $ticket))->toBeFalse();
});
