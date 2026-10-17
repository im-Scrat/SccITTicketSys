<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Resources;

use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The **full** ticket record (SRS FR-TKT-001..012).
 *
 * Rendered only where {@see TicketVisibility}
 * returns `LEVEL_FULL` — the reporter's own ticket, a technician's assigned
 * ticket, or any ticket for an administrator. A requester browsing the community
 * gets {@see TicketFeedResource} instead; the controller chooses between them,
 * so the same route serves both without either projection knowing about the
 * other.
 *
 * Three groups of fields are **administrator-gated inside this resource** rather
 * than split into a fourth class: SLA posture, the AI snapshot, and the
 * duplicate chain. They are operational management data that a reporter has no
 * use for, but an assigned technician legitimately needs some of — so the gate
 * is per-field on the caller's role, and `whenAdministrative()` makes each
 * decision visible at the point of use.
 *
 * @mixin Ticket
 */
class TicketDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pcUnit = $this->pcUnit;
        $asset = $this->asset;
        $room = $this->locationRoom();
        $status = $this->statusRow();
        $floor = $room?->floor;
        $building = $floor?->building;

        $isStaff = in_array($request->user()?->role?->slug, ['administrator', 'technician'], true);
        $isAdministrator = $request->user()?->role?->slug === 'administrator';

        return [
            'id' => $this->uuid,
            'ticket_number' => $this->ticket_number,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source->value,

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
                /*
                 * Phase 2.9 — which rule set it (category / reporter /
                 * location / ai / manual); null for tickets older than the
                 * column. **Staff only, and absent rather than null for anyone
                 * else** (SDD DD-75), so a requester is never told *which*
                 * rule decided their level. That is all the omission does: it
                 * removes the label, not the evidence. A reporter who chose Low
                 * and reads back High can still see a room floor raised it, as
                 * they could under D-B — requesters are shown their ticket's
                 * priority by design, and the floor's value is not treated as
                 * confidential. Keeping a floor genuinely private would need a
                 * separate decision about the level itself, not this key.
                 */
                'source' => $this->when($isStaff, fn (): ?string => $this->priority_source?->value),
            ],
            'category' => [
                'slug' => $this->category?->slug,
                'label' => $this->category?->name,
                // The reporter's own words for an "Other" category (SDD DD-77).
                // Full record and staff list only — never the community card.
                'detail' => $this->category_detail,
            ],

            'reporter' => $this->reporter !== null ? [
                'id' => $this->reporter->uuid,
                'name' => $this->reporter->fullName(),
                // Contact detail only for the people who may need to follow up.
                'email' => $isStaff ? $this->reporter->email : null,
            ] : null,

            // The assignment is operational information: a requester sees only
            // *that* someone is working it, never who or with what internals.
            'technician' => $isStaff && $this->assignedTechnician !== null ? [
                'id' => $this->assignedTechnician->uuid,
                'name' => $this->assignedTechnician->fullName(),
            ] : null,
            'is_assigned' => $this->assigned_technician_id !== null,

            /*
             * Equipment context. Deliberately the same label-only shape the
             * narrow lookup returns, plus the uuid — the uuid lets an
             * administrator *navigate* to the PC page, where the Assets module's
             * own authorization applies. It is not itself asset data.
             */
            'pc_unit' => $pcUnit !== null ? [
                'id' => $pcUnit->uuid,
                'label' => $pcUnit->pc_name,
                'identifier' => $pcUnit->unit_code,
            ] : null,
            // The same label-only shape for another piece of equipment
            // (SDD DD-79). A ticket names at most one of the two.
            'asset' => $asset !== null ? [
                'id' => $asset->uuid,
                'label' => $asset->displayName(),
                'identifier' => $asset->asset_tag,
            ] : null,
            'location' => $room !== null ? [
                'room' => $room->name,
                'floor' => $floor?->name,
                'building' => $building?->name,
            ] : null,

            'upvote_count' => (int) $this->upvote_count,
            'comment_count' => (int) $this->comment_count,
            'attachment_count' => (int) $this->attachment_count,
            'has_voted' => (bool) ($this->has_voted ?? false),

            'tags' => $this->whenLoaded('tags', fn (): array => $this->tags
                ->map(fn ($tag): array => ['slug' => $tag->slug, 'label' => $tag->name])
                ->all()),

            'duplicate_of' => $this->duplicateOf !== null ? [
                'id' => $this->duplicateOf->uuid,
                'ticket_number' => $this->duplicateOf->ticket_number,
                'title' => $this->duplicateOf->title,
            ] : null,

            'reported_at' => $this->created_at?->toIso8601String(),
            'first_response_at' => $isStaff ? $this->first_response_at?->toIso8601String() : null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'reopened_at' => $this->reopened_at?->toIso8601String(),

            /*
             * SLA is management data. A reporter seeing "resolution due in 40
             * minutes" invites a conversation about the clock rather than the
             * fault, so it is staff-only.
             */
            'sla' => $isStaff ? [
                'response_due_at' => $this->response_due_at?->toIso8601String(),
                'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            ] : null,

            /*
             * The AI snapshot columns exist in the schema but nothing writes
             * them yet — the analysis job is a later phase. Exposed to
             * administrators so the field is wired when it arrives, and rendered
             * as "not analysed" rather than faked (SDD, Phase 2.6 §16).
             */
            'ai' => $isAdministrator ? [
                'summary' => $this->ai_summary,
                'confidence' => $this->ai_confidence !== null ? (float) $this->ai_confidence : null,
                'estimated_minutes' => $this->estimated_resolution_minutes,
                'technician_required' => (bool) $this->technician_required,
                'analysed' => $this->ai_summary !== null,
            ] : null,

            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('attachments')),

            'archived' => $this->deleted_at !== null,
            'archived_at' => $isAdministrator ? $this->deleted_at?->toIso8601String() : null,
        ];
    }
}
