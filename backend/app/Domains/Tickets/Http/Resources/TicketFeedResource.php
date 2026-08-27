<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Resources;

use App\Domains\Assets\Http\Resources\AssetOptionResource;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * **The restricted community projection** (SRS FR-TKT-013; SDD DD-41).
 *
 * This is the entire ticket surface one requester sees of another requester's
 * report — enough to recognise "someone already reported this printer" and to
 * participate through an upvote or a comment (UCS-02), and nothing more.
 *
 * What it omits matters far more than what it carries. There is deliberately
 * **no** field here for: the full description, attachments or repair evidence,
 * any internal comment, the assigned technician or assignment internals, SLA and
 * escalation timestamps, the AI snapshot columns, blame columns, or the
 * reporter's email, employee number or contact details.
 *
 * That omission is the **security control**, not a filter applied on the way
 * out. As with {@see AssetOptionResource}, the class cannot express the
 * sensitive fields at all, so widening the audience later still cannot leak
 * them, and no future edit to a controller can accidentally include one.
 *
 * The same projection serves the feed, search results **and** direct
 * `/tickets/feed/{uuid}` access — a requester who pastes someone else's uuid
 * gets this card, never the full record.
 *
 * @mixin Ticket
 */
class TicketFeedResource extends JsonResource
{
    /** Characters of description shown on a card. */
    private const EXCERPT_LENGTH = 200;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pcUnit = $this->pcUnit;
        $room = $this->locationRoom();
        $status = $this->statusRow();
        $building = $room?->floor?->building;

        return [
            'id' => $this->uuid,
            'ticket_number' => $this->ticket_number,
            'title' => $this->title,

            // An excerpt, never the full text: a description can contain details
            // the reporter would not choose to broadcast.
            'excerpt' => Str::limit((string) $this->description, self::EXCERPT_LENGTH),

            'status' => [
                'slug' => $status?->slug,
                'label' => $status?->name,
                'color' => $status?->color,
                'is_open' => $status !== null && $status->is_open,
            ],
            'priority' => [
                'slug' => $this->priority?->slug,
                'label' => $this->priority?->name,
                'level' => $this->priority !== null ? (int) $this->priority->level : null,
            ],
            'category' => [
                'slug' => $this->category?->slug,
                'label' => $this->category?->name,
            ],

            // Equipment as a *label* only — the same shape the narrow lookup
            // returns, so the feed can never become a route into the register.
            'pc_unit' => $pcUnit !== null ? [
                'label' => $pcUnit->pc_name,
                'identifier' => $pcUnit->unit_code,
            ] : null,
            'location' => $room !== null ? [
                'room' => $room->name,
                'building' => $building?->name,
            ] : null,

            // A display name so the community can see who is affected. No email,
            // employee number or contact detail.
            'reporter' => [
                'name' => $this->reporter?->fullName(),
            ],

            'upvote_count' => (int) $this->upvote_count,
            'comment_count' => (int) $this->comment_count,
            'has_voted' => (bool) ($this->has_voted ?? false),
            'is_mine' => $this->reporter_id === $request->user()?->getKey(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
