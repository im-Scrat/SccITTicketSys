<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Models\TicketComment;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The same contract as TicketVisibilityTest, asserted against real HTTP.
 *
 * The unit-level suite proves the service and the policies agree. This one
 * proves the *routes* do — that no controller forgets to authorize, that a
 * direct uuid is not a way past a scoped list, and that the redaction survives
 * serialization all the way to the response body.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');
});

it('requires authentication for every ticket endpoint', function () {
    $ticket = ticketFor($this->teacher);

    $this->getJson('/api/tickets/feed')->assertUnauthorized();
    $this->getJson('/api/tickets/mine')->assertUnauthorized();
    $this->getJson('/api/admin/tickets')->assertUnauthorized();
    $this->getJson("/api/tickets/{$ticket->uuid}")->assertUnauthorized();
    $this->postJson('/api/tickets', [])->assertUnauthorized();
});

/* ------------------------------------------------------- technician scope */

it('closes the feed and the directory to a technician', function () {
    $this->actingAs($this->technician)->getJson('/api/tickets/feed')->assertForbidden();
    $this->actingAs($this->technician)->getJson('/api/admin/tickets')->assertForbidden();
    $this->actingAs($this->technician)->getJson('/api/admin/tickets/dashboard')->assertForbidden();
});

it('refuses a technician direct access to an unassigned ticket', function () {
    $ticket = ticketFor($this->teacher);

    // The uuid is valid and the ticket exists — the refusal is about the
    // relationship, not the identifier.
    $this->actingAs($this->technician)->getJson("/api/tickets/{$ticket->uuid}")->assertForbidden();
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")->assertForbidden();
    $this->actingAs($this->technician)->getJson("/api/tickets/{$ticket->uuid}/comments")->assertForbidden();
    $this->actingAs($this->technician)->postJson("/api/tickets/{$ticket->uuid}/accept")->assertNotFound();
});

it('lets a technician reach only their own assigned ticket', function () {
    $mine = ticketFor($this->teacher, 'assigned');
    $theirs = ticketFor($this->otherTeacher, 'assigned');

    assign($mine, $this->technician, AssignmentStatus::Accepted);
    assign($theirs, userWithRole('technician'), AssignmentStatus::Accepted);

    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$mine->uuid}")->assertOk();
    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$theirs->uuid}")->assertForbidden();

    $queue = $this->actingAs($this->technician)->getJson('/api/tickets/assigned')->assertOk()->json('data');

    expect(collect($queue)->pluck('id'))->toContain($mine->uuid)->not->toContain($theirs->uuid);
});

it('keeps a completed ticket readable but refuses every write', function () {
    $ticket = ticketFor($this->teacher, 'resolved');
    assign($ticket, $this->technician, AssignmentStatus::Completed);

    $this->actingAs($this->technician)
        ->getJson("/api/tickets/assigned/{$ticket->uuid}")
        ->assertOk()
        ->assertJsonPath('meta.read_only', true);

    // No active assignment: every assignment action is refused.
    foreach (['start', 'hold', 'complete'] as $action) {
        $this->actingAs($this->technician)
            ->postJson("/api/tickets/assigned/{$ticket->uuid}/{$action}")
            ->assertForbidden();
    }
});

it('revokes access entirely once a technician declines', function () {
    $ticket = ticketFor($this->teacher, 'assigned');
    assign($ticket, $this->technician, AssignmentStatus::Declined);

    $this->actingAs($this->technician)->getJson("/api/tickets/assigned/{$ticket->uuid}")->assertForbidden();
});

/*
 * Evidence follows `manageAttachments` — the reporter and the people working the
 * ticket — not `tickets.create`. A Technician holds `tickets.update` and *not*
 * `tickets.create`, so gating the route on the latter would have silently made
 * the policy's technician branch unreachable: the one person whose photograph
 * of the repaired machine the record most needs could never attach it.
 */
it('lets the assigned technician attach evidence even though they cannot create tickets', function () {
    Storage::fake('local');

    $ticket = ticketFor($this->teacher, 'in-progress');
    assign($ticket, $this->technician, AssignmentStatus::InProgress);

    expect($this->technician->hasPermissionTo('tickets.create'))->toBeFalse();

    $this->actingAs($this->technician)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('repaired.jpg')],
        ['Accept' => 'application/json'],
    )->assertCreated();
});

it('refuses attachments from a requester who only sees the community card', function () {
    Storage::fake('local');

    $ticket = ticketFor($this->teacher, 'open');

    // Visible as a card, and commentable — but the evidence is not theirs to
    // add to, and not theirs to read either.
    $this->actingAs($this->otherTeacher)->post(
        "/api/tickets/{$ticket->uuid}/attachments",
        ['file' => UploadedFile::fake()->image('unrelated.jpg')],
        ['Accept' => 'application/json'],
    )->assertForbidden();

    $this->actingAs($this->otherTeacher)
        ->getJson("/api/tickets/{$ticket->uuid}/attachments")
        ->assertForbidden();
});

/* ----------------------------------------------------------- teacher scope */

it('closes every administrative route to a teacher', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->getJson('/api/admin/tickets')->assertForbidden();
    $this->actingAs($this->teacher)->getJson('/api/admin/tickets/dashboard')->assertForbidden();
    $this->actingAs($this->teacher)->getJson("/api/admin/tickets/{$ticket->uuid}")->assertForbidden();
    $this->actingAs($this->teacher)->putJson("/api/admin/tickets/{$ticket->uuid}/priority", ['priority' => 'critical'])->assertForbidden();
    $this->actingAs($this->teacher)->postJson("/api/admin/tickets/{$ticket->uuid}/assign", [
        'technician' => $this->technician->uuid,
    ])->assertForbidden();
});

it('serves another reporter’s ticket as the restricted card, never the record', function () {
    // The card shows a 200-character excerpt by design, so the marker is placed
    // *beyond* that boundary — this asserts the truncation actually holds, not
    // merely that the field exists.
    $ticket = ticketFor($this->otherTeacher, 'assigned', [
        'description' => str_repeat('Routine detail about the fault. ', 12).'SENSITIVEDETAIL',
        'response_due_at' => now()->addHours(2),
        'resolution_due_at' => now()->addDay(),
        'ai_summary' => 'CONFIDENTIALAISUMMARY',
        'assigned_technician_id' => $this->technician->id,
    ]);

    TicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $this->admin->id,
        'is_internal' => true,
        'body' => 'INTERNALNOTEBODY',
    ]);

    $response = $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}")->assertOk();
    $body = json_encode($response->json());

    // The card carries what a duplicate-hunter needs…
    $response->assertJsonPath('data.ticket_number', $ticket->ticket_number)
        ->assertJsonPath('data.title', $ticket->title);

    // …and structurally cannot carry anything else.
    expect($body)->not->toContain('SENSITIVEDETAIL')
        ->and($body)->not->toContain('CONFIDENTIALAISUMMARY')
        ->and($body)->not->toContain('INTERNALNOTEBODY')
        ->and($body)->not->toContain('resolution_due_at')
        ->and($body)->not->toContain('ai_summary')
        ->and($body)->not->toContain('technician')
        ->and($body)->not->toContain('attachment');
});

it('gives the reporter the full record of their own ticket', function () {
    $ticket = ticketFor($this->teacher, 'open', ['description' => 'MYOWNDESCRIPTION here.']);

    $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$ticket->uuid}")
        ->assertOk()
        ->assertJsonPath('data.description', 'MYOWNDESCRIPTION here.')
        ->assertJsonStructure(['meta' => ['transitions', 'can', 'reopen_window_days']]);
});

it('hides internal comments from a requester’s comment list', function () {
    $ticket = ticketFor($this->teacher);

    TicketComment::factory()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->admin->id,
        'is_internal' => true, 'body' => 'STAFFONLYNOTE',
    ]);
    TicketComment::factory()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->teacher->id,
        'is_internal' => false, 'body' => 'PUBLICREPLY',
    ]);

    $body = json_encode(
        $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/comments")->assertOk()->json()
    );

    expect($body)->toContain('PUBLICREPLY')->and($body)->not->toContain('STAFFONLYNOTE');

    // The administrator sees both.
    $adminBody = json_encode(
        $this->actingAs($this->admin)->getJson("/api/tickets/{$ticket->uuid}/comments")->assertOk()->json()
    );
    expect($adminBody)->toContain('STAFFONLYNOTE');
});

it('refuses a requester posting an internal note', function () {
    $ticket = ticketFor($this->teacher);

    // Prohibited outright, not silently coerced to public — a note the author
    // believed was private must never be quietly published.
    $this->actingAs($this->teacher)
        ->postJson("/api/tickets/{$ticket->uuid}/comments", ['body' => 'Trying', 'is_internal' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_internal');
});

it('refuses a requester setting priority or reporting on behalf', function () {
    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Projector will not power on',
        'description' => 'The projector in Lab 2 shows no light at all.',
        'category' => 'hardware',
        'priority' => 'critical',
    ])->assertStatus(422)->assertJsonValidationErrors('priority');

    $this->actingAs($this->teacher)->postJson('/api/tickets', [
        'title' => 'Projector will not power on',
        'description' => 'The projector in Lab 2 shows no light at all.',
        'category' => 'hardware',
        'reporter' => $this->otherTeacher->uuid,
    ])->assertStatus(422)->assertJsonValidationErrors('reporter');
});

/* ------------------------------------------------------------- admin scope */

it('lets an administrator through every ticket surface', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->admin)->getJson('/api/tickets/feed')->assertOk();
    $this->actingAs($this->admin)->getJson('/api/admin/tickets')->assertOk();
    $this->actingAs($this->admin)->getJson('/api/admin/tickets/dashboard')->assertOk();
    $this->actingAs($this->admin)->getJson("/api/admin/tickets/{$ticket->uuid}")->assertOk();
    $this->actingAs($this->admin)->getJson('/api/tickets/options')->assertOk();
});

it('shapes the options payload by role', function () {
    $teacherOptions = $this->actingAs($this->teacher)->getJson('/api/tickets/options')->assertOk()->json('data');
    $adminOptions = $this->actingAs($this->admin)->getJson('/api/tickets/options')->assertOk()->json('data');

    // A requester cannot set a priority, so offering the list would describe a
    // control that does not exist for them.
    expect($teacherOptions['priorities'])->toBeEmpty()
        ->and($teacherOptions['technicians'])->toBeEmpty()
        ->and($teacherOptions['categories'])->not->toBeEmpty()
        ->and($adminOptions['priorities'])->not->toBeEmpty()
        ->and($adminOptions['technicians'])->not->toBeEmpty();
});

/* --------------------------------------------------------------- IDOR shape */

it('keeps numeric ids unaddressable', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->admin)->getJson("/api/tickets/{$ticket->id}")->assertNotFound();
    $this->actingAs($this->admin)->getJson('/api/tickets/not-a-uuid')->assertNotFound();
});

it('does not let a literal route segment be read as a uuid', function () {
    // `/tickets/assigned` and `/tickets/options` must win over `/tickets/{uuid}`.
    $this->actingAs($this->technician)->getJson('/api/tickets/assigned')->assertOk();
    $this->actingAs($this->teacher)->getJson('/api/tickets/options')->assertOk();
    $this->actingAs($this->teacher)->getJson('/api/tickets/mine')->assertOk();
});
