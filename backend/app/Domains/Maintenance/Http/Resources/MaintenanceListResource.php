<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Resources;

use App\Models\MaintenanceRecord;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A compact maintenance row for the technician queue, the history list, the
 * preventive horizon and the Administrator directory (SRS FR-MNT-003/007/011).
 *
 * There is only one list shape, because there is only one audience: every
 * surface that renders this is staff doing or overseeing the work. Tickets
 * needed a second, redacted shape because a Teacher can discover another
 * requester's ticket; nothing in FR-MNT lets anyone reach a maintenance record
 * they are not party to, so a redacted projection would have no reader.
 *
 * `checklist` is summarised rather than embedded: a table row wants "3 of 7",
 * and the items themselves belong to the detail view.
 *
 * @mixin MaintenanceRecord
 */
class MaintenanceListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->targetRoom();
        $scheduled = $this->scheduled_for;

        return [
            'id' => $this->uuid,
            'title' => $this->title,

            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'tone' => $this->statusTone(),
                'is_open' => $this->isOpen(),
            ],
            'type' => [
                'slug' => $this->type?->slug,
                'label' => $this->type?->name,
                'is_preventive' => (bool) $this->type?->is_preventive,
            ],

            'technician' => $this->technician !== null ? [
                'id' => $this->technician->uuid,
                'name' => $this->technician->fullName(),
            ] : null,
            'created_by' => $this->createdBy !== null ? [
                'id' => $this->createdBy->uuid,
                'name' => $this->createdBy->fullName(),
            ] : null,

            'target' => $this->targetSummary(),
            'location' => $room !== null ? [
                'room' => $room->name,
                'building' => $room->floor?->building?->name,
            ] : null,

            'ticket' => $this->ticket !== null ? [
                'id' => $this->ticket->uuid,
                'number' => $this->ticket->ticket_number,
                'title' => $this->ticket->title,
            ] : null,

            'checklist' => [
                'total' => (int) ($this->checklists_count ?? 0),
                'completed' => (int) ($this->completed_checklists_count ?? 0),
            ],
            'evidence_count' => (int) ($this->images_count ?? 0),
            'note_count' => (int) ($this->notes_count ?? 0),

            'downtime_minutes' => $this->downtime_minutes,
            'labor_hours' => $this->labor_hours,
            'cost' => $this->cost,

            'scheduled_for' => $scheduled?->toIso8601String(),
            // Derived here rather than stored: "overdue" is a statement about
            // now, and a persisted flag would be wrong the moment the clock
            // moved past it without anyone writing a row.
            'overdue' => $this->isOpen() && $scheduled !== null && $scheduled->isPast(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'maintenance_date' => $this->maintenance_date?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'archived' => $this->deleted_at !== null,
        ];
    }

    /**
     * The machine this visit is about.
     *
     * A record may target a PC unit, a standalone asset, or both — the table's
     * CHECK requires at least one. The PC is named first because that is the
     * common case and the one a technician recognises.
     *
     * @return array<string, mixed>|null
     */
    protected function targetSummary(): ?array
    {
        if ($this->pcUnit !== null) {
            return [
                'kind' => 'pc_unit',
                'id' => $this->pcUnit->uuid,
                'label' => $this->pcUnit->pc_name,
                'identifier' => $this->pcUnit->unit_code,
            ];
        }

        if ($this->asset !== null) {
            return [
                'kind' => 'asset',
                'id' => $this->asset->uuid,
                'label' => $this->asset->displayName(),
                'identifier' => $this->asset->asset_tag,
            ];
        }

        return null;
    }

    /** Where the target sits — the record itself has no room of its own. */
    protected function targetRoom(): ?Room
    {
        return $this->pcUnit->room ?? $this->asset?->currentRoom;
    }

    /**
     * Presentation tone for the status badge — `MaintenanceStatus::tone()`,
     * kept as a method here so subclasses read it the way they always have.
     */
    protected function statusTone(): string
    {
        return $this->status->tone();
    }
}
