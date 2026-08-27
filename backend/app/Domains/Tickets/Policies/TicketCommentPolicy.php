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
 * Two rules carry most of the weight:
 *
 *  1. **An internal note is never a requester's to read.** This policy refuses
 *     it, and the comment *query* excludes it as well — an internal note must
 *     not enter a requester's result set at all, rather than being filtered out
 *     of a resource after the fact.
 *  2. **Editing is time-boxed for the author, unlimited for a moderator.** The
 *     author's window comes from `tickets.comment_edit_window_minutes`; an
 *     Administrator may edit or remove any comment at any time, and doing so
 *     stamps `edited_by`/`deleted_by` and writes an audit entry, because
 *     moderating someone else's words is an administrative act.
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
     * Edit a comment: the author inside the configured window, or an
     * administrator moderating.
     */
    public function update(User $actor, TicketComment $comment): bool
    {
        if ($this->isModerator($actor)) {
            return true;
        }

        return $comment->user_id === $actor->getKey()
            && $actor->hasPermissionTo('tickets.comment')
            && $this->withinEditWindow($comment);
    }

    /** Remove a comment: same rule as editing. */
    public function delete(User $actor, TicketComment $comment): bool
    {
        return $this->update($actor, $comment);
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
