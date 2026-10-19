<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\PermissionGrantType;
use App\Enums\TicketUpdateType;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketUpdate;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Phase 2.9 — what the community feed, comments and ticket history may tell a
 * Teacher about tickets and people that are not theirs.
 *
 * Redaction is asserted against the **encoded** response wherever a field is
 * withheld, so a later resource change cannot quietly put it back.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher', ['first_name' => 'Rowena', 'last_name' => 'Villanueva']);
    $this->otherTeacher = userWithRole('teacher');
});

/* ---------------------------------------------------------------- feed: reporter */

it('never sends another teacher the reporter’s name on the feed', function () {
    ticketFor($this->teacher);

    $response = $this->actingAs($this->otherTeacher)->getJson('/api/tickets/feed')->assertOk()
        ->assertJsonPath('data.0.reporter.name', null)
        ->assertJsonPath('data.0.reporter.label', 'A colleague');

    expect($response->getContent())->not->toContain('Villanueva')
        ->and($response->getContent())->not->toContain('Rowena');
});

it('labels the reporter’s own ticket as theirs', function () {
    ticketFor($this->teacher);

    $this->actingAs($this->teacher)->getJson('/api/tickets/feed')->assertOk()
        ->assertJsonPath('data.0.reporter.label', 'You')
        ->assertJsonPath('data.0.is_mine', true);
});

it('shows an administrator who reported the ticket', function () {
    ticketFor($this->teacher);

    $this->actingAs($this->admin)->getJson('/api/tickets/feed')->assertOk()
        ->assertJsonPath('data.0.reporter.name', 'Rowena Villanueva');
});

it('withholds the reporter on a direct community-card read by uuid too', function () {
    $ticket = ticketFor($this->teacher);

    $response = $this->actingAs($this->otherTeacher)->getJson("/api/tickets/feed/{$ticket->uuid}")->assertOk();

    expect($response->getContent())->not->toContain('Villanueva');
});

/* ---------------------------------------------------------- comment count & authors */

it('does not count internal notes in the comment count a teacher sees', function () {
    $ticket = ticketFor($this->teacher);
    TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->otherTeacher->id]);
    TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->admin->id, 'is_internal' => true]);
    TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->admin->id, 'is_internal' => true]);

    $this->actingAs($this->otherTeacher)->getJson("/api/tickets/feed/{$ticket->uuid}")->assertOk()
        ->assertJsonPath('data.comment_count', 1);
});

it('keeps the cached count right when a comment flips between public and internal', function () {
    $ticket = ticketFor($this->teacher);
    $comment = TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->admin->id]);

    $comment->forceFill(['is_internal' => true])->save();
    expect(Ticket::query()->find($ticket->id)?->comment_count)->toBe(0);

    $comment->forceFill(['is_internal' => false])->save();
    expect(Ticket::query()->find($ticket->id)?->comment_count)->toBe(1);
});

it('drops a soft-deleted public comment from the count', function () {
    $ticket = ticketFor($this->teacher);
    $comment = TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->otherTeacher->id]);

    $comment->delete();

    expect(Ticket::query()->find($ticket->id)?->comment_count)->toBe(0);
});

it('recounts existing rows under the public-only meaning', function () {
    $ticket = ticketFor($this->teacher);
    TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->admin->id, 'is_internal' => true]);
    // Simulate a value cached under the old meaning.
    DB::table('tickets')->where('id', $ticket->id)->update(['comment_count' => 7]);

    $migration = require database_path('migrations/2026_10_02_100000_exclude_internal_notes_from_comment_count.php');
    $migration->up();

    expect(DB::table('tickets')->where('id', $ticket->id)->value('comment_count'))->toBe(0);
});

it('does not send a teacher the account uuid of a comment’s author', function () {
    $ticket = ticketFor($this->teacher);
    TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->otherTeacher->id]);

    $response = $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/comments")->assertOk()
        ->assertJsonPath('data.0.author.id', null);

    expect($response->getContent())->not->toContain($this->otherTeacher->uuid);
});

it('still sends an administrator the comment author’s uuid for moderation', function () {
    $ticket = ticketFor($this->teacher);
    TicketComment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->otherTeacher->id]);

    $this->actingAs($this->admin)->getJson("/api/tickets/{$ticket->uuid}/comments")->assertOk()
        ->assertJsonPath('data.0.author.id', $this->otherTeacher->uuid);
});

/* ------------------------------------------------------------------- assignment */

it('refuses a deputized technician assigning a ticket they cannot see', function () {
    givePermission($this->technician, 'tickets.assign', PermissionGrantType::Grant);
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->technician)
        ->postJson("/api/admin/tickets/{$ticket->uuid}/assign", ['technician' => $this->technician->uuid])
        ->assertForbidden();
});

it('lets a deputized technician assign a ticket they are connected to', function () {
    givePermission($this->technician, 'tickets.assign', PermissionGrantType::Grant);
    $ticket = ticketFor($this->teacher);
    assign($ticket, $this->technician, AssignmentStatus::Accepted);
    // The shape an accepted assignment really has. Left `open`, the hand-over
    // would need the open→assigned edge, which the lifecycle reserves for an
    // Administrator — a separate rule from the one under test here.
    $ticket->forceFill([
        'current_status_id' => ticketStatus('assigned')->id,
        'assigned_technician_id' => $this->technician->id,
    ])->save();
    $colleague = userWithRole('technician');

    $this->actingAs($this->technician)
        ->postJson("/api/admin/tickets/{$ticket->uuid}/assign", ['technician' => $colleague->uuid])
        ->assertSuccessful();
});

/* ------------------------------------------------------------------- throttling */

it('throttles ticket creation per user', function () {
    config(['security.rate_limits.tickets_per_hour' => 1]);

    // The limiter counts attempts, so an invalid payload is enough to spend it.
    $this->actingAs($this->teacher)->postJson('/api/tickets', [])->assertStatus(422);
    $this->actingAs($this->teacher)->postJson('/api/tickets', [])->assertStatus(429);
});

it('does not let reading the AI panel spend the ticket-creation budget', function () {
    config(['security.rate_limits.tickets_per_hour' => 1]);
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertOk();
    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/ai-analysis")->assertOk();

    $this->actingAs($this->teacher)->postJson('/api/tickets', [])->assertStatus(422);
});

/* ------------------------------------------------------------------ timeline */

it('refuses another teacher’s ticket history — it is part of the full record', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->otherTeacher)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertForbidden();
});

it('serves another teacher only the anonymous card on the ticket uuid itself', function () {
    $ticket = ticketFor($this->teacher);

    // TicketController::show answers a community-level reader with the card,
    // so the reporter redaction has to hold on this route as well.
    $response = $this->actingAs($this->otherTeacher)->getJson("/api/tickets/{$ticket->uuid}")->assertOk()
        ->assertJsonPath('data.reporter.label', 'A colleague');

    expect($response->getContent())->not->toContain('Villanueva');
});

it('refuses a technician with no assignment the ticket history', function () {
    $ticket = ticketFor($this->teacher);

    $this->actingAs($this->technician)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertForbidden();
});

it('gives the reporter structural facts but no staff words or identities', function () {
    $ticket = ticketFor($this->teacher);
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->admin->id,
        'update_type' => TicketUpdateType::Assignment->value,
        'body' => 'Staff-only remark about the budget',
        'metadata' => ['technician' => 'Teodoro Bautista', 'technician_id' => $this->technician->uuid, 'reassigned' => false],
        'created_at' => now(),
    ]);
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->technician->id,
        'update_type' => TicketUpdateType::Assignment->value,
        'body' => 'Declining: not my building',
        'metadata' => ['assignment_status' => 'declined', 'technician' => 'Teodoro Bautista', 'phrase' => 'declined'],
        'created_at' => now(),
    ]);
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->admin->id,
        'update_type' => TicketUpdateType::StatusChange->value,
        'body' => 'Internal reasoning',
        'metadata' => ['from_label' => 'Open', 'to_label' => 'Assigned'],
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertOk();
    $entries = collect($response->json('data'));

    expect($entries->pluck('kind')->all())->toBe(['assignment', 'status'])
        ->and($entries->firstWhere('kind', 'status')['to'])->toBe('Assigned')
        ->and($entries->firstWhere('kind', 'status')['actor'])->toBe('IT team');

    $encoded = $response->getContent();
    foreach (['Teodoro', 'Staff-only remark', 'Declining', 'Internal reasoning', $this->technician->uuid] as $secret) {
        expect($encoded)->not->toContain($secret);
    }
});

it('shows the reporter their own words in their own history', function () {
    $ticket = ticketFor($this->teacher);
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->teacher->id,
        'update_type' => TicketUpdateType::AiAnalysis->value,
        'body' => 'Still no display after reseating the cable',
        'metadata' => ['outcome' => 'not_fixed'],
        'created_at' => now(),
    ]);

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertOk()
        ->assertJsonPath('data.0.kind', 'outcome')
        ->assertJsonPath('data.0.outcome', 'not_fixed')
        ->assertJsonPath('data.0.actor', 'You')
        ->assertJsonPath('data.0.note', 'Still no display after reseating the cable');
});

it('gives staff the names and remarks the reporter does not see', function () {
    $ticket = ticketFor($this->teacher);
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->admin->id,
        'update_type' => TicketUpdateType::Assignment->value,
        'body' => 'Urgent: exam room',
        'metadata' => ['technician' => 'Teodoro Bautista', 'reassigned' => false],
        'created_at' => now(),
    ]);

    $this->actingAs($this->admin)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertOk()
        ->assertJsonPath('data.0.technician', 'Teodoro Bautista')
        ->assertJsonPath('data.0.note', 'Urgent: exam room');
});

it('never puts comments in the history', function () {
    $ticket = ticketFor($this->teacher);
    TicketUpdate::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $this->teacher->id,
        'update_type' => TicketUpdateType::Comment->value,
        'body' => 'a comment', 'created_at' => now(),
    ]);

    $this->actingAs($this->teacher)->getJson("/api/tickets/{$ticket->uuid}/timeline")->assertOk()
        ->assertJsonCount(0, 'data');
});
