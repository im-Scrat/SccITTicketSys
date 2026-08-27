<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A compact ticket row for the **administrator directory** and the **technician
 * queue** (SRS FR-TKT-014, FR-ASN-006).
 *
 * Both surfaces are tables read by staff, so this carries the operational
 * columns a table needs — assignee, SLA deadline, breach posture — that the
 * community card must never show. It is never rendered for a requester: their
 * own list uses {@see TicketFeedResource}, whose shape cannot express these
 * fields at all.
 *
 * `sla` arrives pre-computed from `SlaCalculator` on the collection, so a
 * 20-row page does not run 20 posture calculations against `now()` at slightly
 * different instants.
 *
 * @mixin Ticket
 */
class TicketListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pcUnit = $this->pcUnit;
        $room = $this->locationRoom();
        $status = $this->statusRow();

        return [
            'id' => $this->uuid,
            'ticket_number' => $this->ticket_number,
            'title' => $this->title,

            'status' => [
                'slug' => $status?->slug,
                'label' => $status?->name,
                'color' => $status?->color,
                'is_open' => $status !== null && $status->is_open,
                'is_terminal' => $status !== null && $status->is_terminal,
            ],
            'priority' => [
                'slug' => $this->priority?->slug,
                'label' => $this->priority?->name,
                'level' => $this->priority !== null ? (int) $this->priority->level : null,
                'color' => $this->priority?->color,
            ],
            'category' => [
                'slug' => $this->category?->slug,
                'label' => $this->category?->name,
            ],

            'reporter' => [
                'id' => $this->reporter?->uuid,
                'name' => $this->reporter?->fullName(),
            ],
            'technician' => $this->assignedTechnician !== null ? [
                'id' => $this->assignedTechnician->uuid,
                'name' => $this->assignedTechnician->fullName(),
            ] : null,

            'pc_unit' => $pcUnit !== null ? [
                'id' => $pcUnit->uuid,
                'label' => $pcUnit->pc_name,
                'identifier' => $pcUnit->unit_code,
            ] : null,
            'location' => $room !== null ? [
                'room' => $room->name,
                'building' => $room->floor?->building?->name,
            ] : null,

            'upvote_count' => (int) $this->upvote_count,
            'comment_count' => (int) $this->comment_count,
            'attachment_count' => (int) $this->attachment_count,

            'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            'sla' => $this->sla_posture ?? null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
