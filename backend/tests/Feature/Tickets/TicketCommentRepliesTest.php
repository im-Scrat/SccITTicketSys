<?php

declare(strict_types=1);

use App\Models\Notification as NotificationRecord;
use App\Models\TicketComment;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * Threaded comments — one level deep (SRS FR-TKT-007, SDD DD-74).
 *
 * The listing is made of top-level comments, each carrying its replies, so a
 * thread is never split across pages. A reply always answers a root; a public
 * reply never answers an internal note; and the internal-note exclusion is a
 * query constraint at *both* levels, asserted here against the encoded payload
 * so a later resource change cannot leak a note back in through `replies`.
 */

beforeEach(function () {
    seedRbac();
    $this->seed(TicketLookupSeeder::class);
    $this->seed(SystemSettingSeeder::class);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
    $this->otherTeacher = userWithRole('teacher');

    $this->ticket = ticketFor($this->teacher);
});

/** A comment on the test ticket, by a given author. */
function commentOn($ticket, $author, array $attributes = []): TicketComment
{
    return TicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $author->id,
        'is_internal' => false,
        ...$attributes,
    ]);
}

/* ---------------------------------------------------------------- listing */

it('lists top-level comments with their replies nested, oldest first', function () {
    $first = commentOn($this->ticket, $this->teacher, ['body' => 'FIRSTROOT', 'created_at' => now()->subMinutes(10)]);
    $second = commentOn($this->ticket, $this->otherTeacher, ['body' => 'SECONDROOT', 'created_at' => now()->subMinutes(8)]);
    commentOn($this->ticket, $this->otherTeacher, [
        'body' => 'EARLYREPLY', 'parent_comment_id' => $first->id, 'created_at' => now()->subMinutes(9),
    ]);
    commentOn($this->ticket, $this->admin, [
        'body' => 'LATEREPLY', 'parent_comment_id' => $first->id, 'created_at' => now()->subMinutes(5),
    ]);

    $response = $this->actingAs($this->otherTeacher)
        ->getJson("/api/tickets/{$this->ticket->uuid}/comments")
        ->assertOk()
        // Pagination counts threads, not rows: two roots, four comments.
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->uuid)
        ->assertJsonPath('data.1.id', $second->uuid)
        ->assertJsonCount(2, 'data.0.replies')
        ->assertJsonCount(0, 'data.1.replies')
        ->assertJsonPath('data.0.replies.0.body', 'EARLYREPLY')
        ->assertJsonPath('data.0.replies.1.body', 'LATEREPLY')
        ->assertJsonPath('data.0.replies.0.parent_id', $first->uuid);

    // A reply is never also listed as a root.
    expect(collect($response->json('data'))->pluck('body')->all())->toBe(['FIRSTROOT', 'SECONDROOT']);
});

it('keeps the replies of a removed comment under its tombstone', function () {
    $root = commentOn($this->ticket, $this->otherTeacher, ['body' => 'GONEROOT']);
    commentOn($this->ticket, $this->teacher, ['body' => 'SURVIVINGREPLY', 'parent_comment_id' => $root->id]);
    $root->delete();

    $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$this->ticket->uuid}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.removed', true)
        ->assertJsonPath('data.0.body', null)
        ->assertJsonPath('data.0.replies.0.body', 'SURVIVINGREPLY')
        ->assertJsonPath('data.0.replies.0.parent_id', $root->uuid);
});

it('keeps an internal reply out of a requester’s payload', function () {
    $root = commentOn($this->ticket, $this->teacher, ['body' => 'PUBLICROOT']);
    commentOn($this->ticket, $this->admin, [
        'body' => 'STAFFONLYREPLY', 'is_internal' => true, 'parent_comment_id' => $root->id,
    ]);

    $teacherBody = json_encode(
        $this->actingAs($this->otherTeacher)->getJson("/api/tickets/{$this->ticket->uuid}/comments")->assertOk()->json()
    );
    expect($teacherBody)->toContain('PUBLICROOT')->and($teacherBody)->not->toContain('STAFFONLYREPLY');

    $adminBody = json_encode(
        $this->actingAs($this->admin)->getJson("/api/tickets/{$this->ticket->uuid}/comments")->assertOk()->json()
    );
    expect($adminBody)->toContain('STAFFONLYREPLY');
});

/* ---------------------------------------------------------------- posting */

it('lets a teacher reply to another user’s comment on a ticket they did not report', function () {
    $root = commentOn($this->ticket, $this->teacher, ['body' => 'Started after the update.']);

    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", [
            'body' => 'Same in Lab 2.',
            'parent' => $root->uuid,
        ])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $root->uuid);

    expect(TicketComment::query()->where('parent_comment_id', $root->id)->count())->toBe(1);
});

it('lets a teacher comment on a closed ticket', function () {
    // "Comment at any time": the server has never gated comments on status, and
    // the community view no longer hides the box. Pinned so neither drifts.
    $closed = ticketFor($this->teacher, 'closed');

    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$closed->uuid}/comments", ['body' => 'Still broken here.'])
        ->assertCreated();
});

it('tells the author of a comment when someone replies to it', function () {
    $root = commentOn($this->ticket, $this->otherTeacher, ['body' => 'Is anyone else seeing this?']);

    $this->actingAs($this->admin)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", [
            'body' => 'Yes — a technician is on the way.',
            'parent' => $root->uuid,
        ])
        ->assertCreated();

    $topics = NotificationRecord::query()
        ->where('user_id', $this->otherTeacher->id)
        ->get()
        ->map(fn (NotificationRecord $n): mixed => $n->data['topic'])
        ->all();

    expect($topics)->toContain('ticket.commented');
});

/* -------------------------------------------------------------- rejections */

it('refuses a reply to a reply', function () {
    $root = commentOn($this->ticket, $this->teacher);
    $reply = commentOn($this->ticket, $this->otherTeacher, ['parent_comment_id' => $root->id]);

    $this->actingAs($this->teacher)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", ['body' => 'Nested', 'parent' => $reply->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent');
});

it('refuses a reply to a removed comment', function () {
    $root = commentOn($this->ticket, $this->teacher);
    $root->delete();

    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", ['body' => 'Late', 'parent' => $root->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent');
});

it('refuses a reply to a comment on another ticket', function () {
    $elsewhere = commentOn(ticketFor($this->otherTeacher), $this->otherTeacher);

    $this->actingAs($this->teacher)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", ['body' => 'Grafted', 'parent' => $elsewhere->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent');
});

it('refuses a public reply to an internal note', function () {
    $note = commentOn($this->ticket, $this->technician, ['is_internal' => true]);

    $this->actingAs($this->admin)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", ['body' => 'Public answer', 'parent' => $note->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent');
});

it('accepts an internal reply to an internal note', function () {
    $note = commentOn($this->ticket, $this->technician, ['is_internal' => true]);

    $this->actingAs($this->admin)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", [
            'body' => 'Internal answer', 'parent' => $note->uuid, 'is_internal' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_internal', true);
});

/* ----------------------------------------------------------- authorization */

it('refuses an unassigned technician the thread', function () {
    $this->actingAs($this->technician)
        ->getJson("/api/tickets/{$this->ticket->uuid}/comments")
        ->assertForbidden();
});

it('refuses an unassigned technician a reply', function () {
    $root = commentOn($this->ticket, $this->teacher);

    $this->actingAs($this->technician)
        ->postJson("/api/tickets/{$this->ticket->uuid}/comments", ['body' => 'Hi', 'parent' => $root->uuid])
        ->assertForbidden();
});

it('answers 404 for the thread of a deleted ticket', function () {
    $this->ticket->delete();

    $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$this->ticket->uuid}/comments")
        ->assertNotFound();
});
