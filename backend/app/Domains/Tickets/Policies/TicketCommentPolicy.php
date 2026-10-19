<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Policies;

use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\SystemSetting;
use App\Models\TicketComment;
use App\Models\User;

/**
 * Per-record authorization for ticket comments (SRS FR-TKT-007).
 *
 * Three rules carry most of the weight:
 *
 *  1. **An internal note is never a requester's to read.** This policy refuses
 *     it, and the comment *query* excludes it as well — an internal note must
 *     not enter a requester's result set at all, rather than being filtered out
 *     of a resource after the fact.
 *  2. **Words belong to their author.** Only the author may edit a comment,
 *     and only inside `tickets.comment_edit_window_minutes`. Nobody — an
 *     Administrator included — may rewrite someone else's words: an edited
 *     comment still reads as the author's, so an edit by anyone else would put
 *     words in their mouth (owner decision, 2026-10-19).
 *  3. **Removal is the one moderation power.** An Administrator may remove any
 *     comment at any time — abusive or mistaken content must be able to go — and
 *     doing so leaves a tombstone, stamps `deleted_by` and writes an audit
 *     entry, because moderating someone else's words is an administrative act.
 *     A removal is visible as a removal; an edit would not be.
 */
class TicketCommentPolicy
{
    public function __construct(private readonly TicketVisibility $visibility) {}

    public function view(User $actor, TicketComment $comment): bool
    {
        $ticket = $comment->ticket;

        if ($ticket === null || ! $this->visibility->canSee($actor, $ticket)) {
            return false;
        }

        if ($comment->is_internal) {
            return $this->visibility->canSeeInternal($actor, $ticket);
        }

        return true;
    }

    /**
     * Edit a comment: the author, inside the configured window. There is no
     * moderator bypass — see rule 2 above.
     */
    public function update(User $actor, TicketComment $comment): bool
    {
        return $comment->deleted_at === null
            && $comment->user_id === $actor->getKey()
            && $actor->hasPermissionTo('tickets.comment')
            && $this->withinEditWindow($comment);
    }

    /**
     * Remove a comment: the author inside the window, or an administrator
     * moderating at any time.
     */
    public function delete(User $actor, TicketComment $comment): bool
    {
        if ($comment->deleted_at !== null) {
            return false;
        }

        return $this->isModerator($actor) || $this->update($actor, $comment);
    }

    /** Is this actor acting as a moderator rather than as the author? */
    public function moderate(User $actor, TicketComment $comment): bool
    {
        return $this->isModerator($actor) && $comment->user_id !== $actor->getKey();
    }

    private function isModerator(User $actor): bool
    {
        return $actor->role?->slug === 'administrator'
            && $actor->hasPermissionTo('tickets.comment');
    }

    /**
     * The author's edit window. Configurable so a site can widen it without a
     * deployment; a missing setting falls back to 15 minutes rather than
     * throwing, because a bad settings row should not lock everyone out of
     * correcting a typo.
     */
    private function withinEditWindow(TicketComment $comment): bool
    {
        $minutes = (int) (SystemSetting::query()
            ->where('key', 'tickets.comment_edit_window_minutes')
            ->value('value') ?: 15);

        return $comment->created_at !== null
            && $comment->created_at->diffInMinutes(now()) <= max(1, $minutes);
    }
}
