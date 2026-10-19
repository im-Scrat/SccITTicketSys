<?php

declare(strict_types=1);

use App\Models\TicketComment;
use App\Models\TicketVote;
use Database\Seeders\SystemSettingSeeder;
use Database\Seeders\TicketLookupSeeder;

/*
 * Who may change a comment, and whose upvote a toggle can touch
 * (SRS FR-TKT-007 / FR-TKT-009; owner decision 2026-10-19).
 *
 *  - A comment's words belong to its author: only the author edits, and only
 *    inside the edit window. An administrator may *remove* any comment
 *    (moderation leaves a tombstone) but may never rewrite one.
 *  - The upvote endpoint has no user argument: it toggles the caller's own
 *    vote and can never remove anybody else's.
 *
 * The `can_edit`/`can_remove` flags are asserted on the encoded payload so the
 * client cannot be told it may do something the endpoints refuse.
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

function authoredComment($ticket, $author, array $attributes = []): TicketComment
{
    return TicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $author->id,
        'is_internal' => false,
        'created_at' => now(),
        ...$attributes,
    ]);
}

/* ------------------------------------------------------------ editing */

it('lets the author edit inside the window', function () {
    $comment = authoredComment($this->ticket, $this->teacher);

    $this->actingAs($this->teacher)
        ->putJson("/api/ticket-comments/{$comment->uuid}", ['body' => 'Corrected.'])
        ->assertOk()
        ->assertJsonPath('data.body', 'Corrected.');
});

it('refuses the author once the edit window has closed', function () {
    $comment = authoredComment($this->ticket, $this->teacher, ['created_at' => now()->subHours(2)]);

    $this->actingAs($this->teacher)
        ->putJson("/api/ticket-comments/{$comment->uuid}", ['body' => 'Too late.'])
        ->assertForbidden();

    expect($comment->fresh()->body)->not->toBe('Too late.');
});

it("refuses another teacher editing someone else's comment", function () {
    $comment = authoredComment($this->ticket, $this->teacher);

    $this->actingAs($this->otherTeacher)
        ->putJson("/api/ticket-comments/{$comment->uuid}", ['body' => 'Hijacked.'])
        ->assertForbidden();
});

it("refuses a technician editing someone else's comment", function () {
    $comment = authoredComment($this->ticket, $this->teacher);

    $this->actingAs($this->technician)
        ->putJson("/api/ticket-comments/{$comment->uuid}", ['body' => 'Hijacked.'])
        ->assertForbidden();
});

it("refuses an administrator editing someone else's comment", function () {
    $comment = authoredComment($this->ticket, $this->teacher, ['body' => 'ORIGINAL']);

    $this->actingAs($this->admin)
        ->putJson("/api/ticket-comments/{$comment->uuid}", ['body' => 'Rewritten.'])
        ->assertForbidden();

    expect($comment->fresh()->body)->toBe('ORIGINAL');
});

/* ----------------------------------------------------------- removing */

it('lets the author remove their own comment inside the window', function () {
    $comment = authoredComment($this->ticket, $this->teacher);

    $this->actingAs($this->teacher)
        ->deleteJson("/api/ticket-comments/{$comment->uuid}")
        ->assertOk();

    expect($comment->fresh()->trashed())->toBeTrue();
});

it("refuses another teacher removing someone else's comment", function () {
    $comment = authoredComment($this->ticket, $this->teacher);

    $this->actingAs($this->otherTeacher)
        ->deleteJson("/api/ticket-comments/{$comment->uuid}")
        ->assertForbidden();

    expect($comment->fresh()->trashed())->toBeFalse();
});

it("refuses a technician removing someone else's comment", function () {
    $comment = authoredComment($this->ticket, $this->teacher);

    $this->actingAs($this->technician)
        ->deleteJson("/api/ticket-comments/{$comment->uuid}")
        ->assertForbidden();

    expect($comment->fresh()->trashed())->toBeFalse();
});

it('lets an administrator remove any comment, leaving a tombstone', function () {
    $comment = authoredComment($this->ticket, $this->teacher, ['created_at' => now()->subDays(3)]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/ticket-comments/{$comment->uuid}")
        ->assertOk();

    expect($comment->fresh()->trashed())->toBeTrue();

    $this->actingAs($this->teacher)
        ->getJson("/api/tickets/{$this->ticket->uuid}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.removed', true)
        ->assertJsonPath('data.0.body', null);
});

/* ------------------------------------------------------ payload flags */

it('tells each reader exactly what the endpoints will allow', function () {
    authoredComment($this->ticket, $this->teacher, ['body' => 'MINE']);
    authoredComment($this->ticket, $this->teacher, ['body' => 'STALE', 'created_at' => now()->subHours(2)]);

    $flags = fn ($user): array => collect(
        $this->actingAs($user)->getJson("/api/tickets/{$this->ticket->uuid}/comments")->assertOk()->json('data'),
    )->mapWithKeys(fn (array $row): array => [$row['body'] => [$row['can_edit'], $row['can_remove']]])->all();

    // Listing order is oldest first; compare by key, but the values strictly.
    expect($flags($this->teacher))->toEqual(['MINE' => [true, true], 'STALE' => [false, false]])
        ->and($flags($this->otherTeacher))->toEqual(['MINE' => [false, false], 'STALE' => [false, false]])
        ->and($flags($this->admin))->toEqual(['MINE' => [false, true], 'STALE' => [false, true]])
        ->and(array_merge(...array_values($flags($this->admin))))->each->toBeBool();
});

/* --------------------------------------------------------------- votes */

it("never removes another user's upvote", function () {
    $this->actingAs($this->otherTeacher)
        ->postJson("/api/tickets/{$this->ticket->uuid}/vote")
        ->assertOk()
        ->assertJsonPath('data.voted', true);

    $third = userWithRole('teacher');

    // A second person's toggle adds their own vote; it cannot cancel the first.
    $this->actingAs($third)
        ->postJson("/api/tickets/{$this->ticket->uuid}/vote")
        ->assertOk()
        ->assertJsonPath('data.voted', true)
        ->assertJsonPath('data.upvote_count', 2);

    // Toggling again removes only the caller's own vote.
    $this->actingAs($third)
        ->postJson("/api/tickets/{$this->ticket->uuid}/vote")
        ->assertOk()
        ->assertJsonPath('data.voted', false)
        ->assertJsonPath('data.upvote_count', 1);

    expect(TicketVote::query()->where('ticket_id', $this->ticket->id)->pluck('user_id')->all())
        ->toBe([$this->otherTeacher->id]);
});

it('ignores any attempt to name another voter', function () {
    $this->actingAs($this->otherTeacher)->postJson("/api/tickets/{$this->ticket->uuid}/vote")->assertOk();

    // The endpoint takes no user argument; a smuggled one changes nothing.
    $this->actingAs($this->admin)
        ->postJson("/api/tickets/{$this->ticket->uuid}/vote", ['user' => $this->otherTeacher->uuid, 'user_id' => $this->otherTeacher->id])
        ->assertOk()
        ->assertJsonPath('data.voted', true);

    expect(TicketVote::query()->where('user_id', $this->otherTeacher->id)->exists())->toBeTrue();
});
