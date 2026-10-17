<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Resources;

use App\Models\TicketComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One comment (SRS FR-TKT-007).
 *
 * `is_internal` is carried so the client can badge a staff-only note visibly —
 * a technician needs to *know* which of their words the reporter can read. That
 * is safe because an internal comment never reaches a requester in the first
 * place: the comment query excludes it, rather than this resource hiding it.
 *
 * A soft-deleted comment renders as a tombstone rather than vanishing, so a
 * threaded conversation does not lose its shape when one reply is moderated.
 *
 * @mixin TicketComment
 */
class TicketCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $removed = $this->deleted_at !== null;

        return [
            'id' => $this->uuid,
            'body' => $removed ? null : $this->body,
            'removed' => $removed,
            'is_internal' => (bool) $this->is_internal,
            'is_edited' => (bool) $this->is_edited,
            'edited_at' => $this->edited_at?->toIso8601String(),

            // Posting a comment is a public act, so the author's name and role
            // are shown. Their account uuid is not (Phase 2.9): no teacher-facing
            // screen needs it, and handing every reader a stable identifier for
            // every commenter is a correlation key for nothing in return. Only an
            // Administrator, who moderates, receives it.
            'author' => $this->user !== null ? [
                'id' => $request->user()?->isAdministrator() ? $this->user->uuid : null,
                'name' => $this->user->fullName(),
                'role' => $this->user->role?->slug,
            ] : null,

            'is_mine' => $this->user_id === $request->user()?->getKey(),
            'parent_id' => $this->whenLoaded('parent', fn (): ?string => $this->parent?->uuid),

            // Present on a thread's top-level comment when the listing loaded
            // them; already filtered by the query, like the roots (SDD DD-74).
            'replies' => $this->whenLoaded('replies', fn () => self::collection($this->replies)),

            /*
             * Moderation attribution. Shown only to administrators: a requester
             * needs to know a comment was removed, not which administrator did
             * it — that is audit information, and surfacing it invites the
             * conversation to become about the moderator.
             */
            'moderated_by' => $request->user()?->role?->slug === 'administrator'
                ? ($this->deletedBy?->fullName() ?? $this->editedBy?->fullName())
                : null,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
