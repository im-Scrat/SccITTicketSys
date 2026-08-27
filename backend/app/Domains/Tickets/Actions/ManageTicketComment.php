<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\TicketUpdateType;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Comment creation, editing and removal (SRS FR-TKT-007/012).
 *
 * `tickets.comment_count` is **never written here** — an `AFTER INSERT OR UPDATE
 * OR DELETE` trigger owns it, and it is soft-delete aware, so removing a comment
 * decrements the counter and restoring one puts it back. Touching the column
 * from PHP would double-count.
 *
 * Ordinary commenting writes a `ticket_updates` row (the ticket's own
 * narrative). **Moderation** — an administrator editing or removing someone
 * else's words — additionally writes to `activity_logs`, because that is an
 * administrative act on another person's contribution and belongs in the audit
 * trail rather than only in the conversation.
 */
class ManageTicketComment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(
        Ticket $ticket,
        string $body,
        bool $isInternal,
        User $actor,
        Request $request,
        ?TicketComment $parent = null,
    ): TicketComment {
        return DB::transaction(function () use ($ticket, $body, $isInternal, $actor, $parent): TicketComment {
            $comment = TicketComment::query()->create([
                'ticket_id' => $ticket->getKey(),
                'user_id' => $actor->getKey(),
                'parent_comment_id' => $parent?->getKey(),
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            TicketUpdate::query()->create([
                'ticket_id' => $ticket->getKey(),
                'user_id' => $actor->getKey(),
                'update_type' => TicketUpdateType::Comment->value,
                // An internal note's text does not go into the shared narrative;
                // only the fact that one was added.
                'body' => $isInternal ? null : $body,
                'metadata' => ['is_internal' => $isInternal, 'comment' => $comment->uuid],
                'created_at' => now(),
            ]);

            return $comment;
        })->load('user.role');
    }

    /**
     * Edit a comment. `is_edited`/`edited_at` are stamped so a reader can see
     * the text has changed since it was written.
     */
    public function update(
        TicketComment $comment,
        string $body,
        User $actor,
        Request $request,
    ): TicketComment {
        $moderating = $comment->user_id !== $actor->getKey();
        $original = $comment->body;

        $comment->forceFill([
            'body' => $body,
            'is_edited' => true,
            'edited_at' => now(),
            'edited_by' => $actor->getKey(),
        ])->save();

        if ($moderating) {
            $this->auditModeration($comment, $actor, $request, 'edited', $original);
        }

        return $comment->refresh()->load('user.role');
    }

    /**
     * Remove a comment. Soft delete, so the thread keeps its shape and the
     * counter trigger can put the count back if it is ever restored.
     */
    public function delete(TicketComment $comment, User $actor, Request $request): void
    {
        $moderating = $comment->user_id !== $actor->getKey();
        $original = $comment->body;

        DB::transaction(function () use ($comment, $actor): void {
            // Stamp the moderator *before* deleting: the trigger fires on the
            // update that sets `deleted_at`, and we want both writes in one row
            // version rather than a second update to a deleted row.
            $comment->forceFill(['deleted_by' => $actor->getKey()])->save();
            $comment->delete();
        });

        if ($moderating) {
            $this->auditModeration($comment, $actor, $request, 'removed', $original);
        }
    }

    private function auditModeration(
        TicketComment $comment,
        User $actor,
        Request $request,
        string $verb,
        ?string $original,
    ): void {
        // Both FKs are NOT NULL, so the relations are typed non-null — but the
        // rows can be absent from a partially-loaded model. Resolve once here
        // rather than chaining nullsafe inside the message.
        $ticket = $comment->getRelationValue('ticket');
        $author = $comment->getRelationValue('user');

        $ticketNumber = $ticket instanceof Ticket ? $ticket->ticket_number : 'a ticket';
        $authorName = $author instanceof User ? $author->fullName() : 'a user';

        $this->audit->activity(
            ActivityAction::TicketCommentModerated,
            actor: $actor,
            subject: $ticket instanceof Ticket ? $ticket : null,
            properties: [
                'comment' => $comment->uuid,
                'action' => $verb,
                'author' => $author instanceof User ? $author->fullName() : null,
                // The original text is kept in the audit trail: moderation must
                // be reviewable, and "a comment was removed" without knowing
                // what it said is not a reviewable record.
                'original_body' => $original,
            ],
            request: $request,
            module: 'tickets',
            description: sprintf('Comment by %s %s on %s', $authorName, $verb, $ticketNumber),
        );
    }
}
