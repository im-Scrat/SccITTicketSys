<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Resources;

use App\Models\WorkSupportRequest;
use App\Models\WorkSupportRequestAttachment;
use App\Models\WorkSupportRequestItem;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One work support request, in full (SRS FR-WSR-005/008/009).
 *
 * ── One projection, not two ────────────────────────────────────────────────
 *
 * The administrator inbox and the technician's tracking page render the *same*
 * shape. That is deliberate and it is safe, because the two surfaces differ in
 * **which rows** they can reach, not in which fields those rows may show:
 * `WorkSupportVisibility` gives a technician only their own requests, and a
 * technician looking at their own request may see all of it — including who
 * decided it and why.
 *
 * Two resources would have meant two places to add a field and one place to
 * forget it. The row scope is the boundary; this class is a shape.
 *
 * ── What it deliberately has no field for ──────────────────────────────────
 *
 * No storage path on an attachment (the bytes come only from the authorized
 * download route), no maintenance cost or labour hours, no PC unit record
 * beyond identity and location, and no other technician's anything. The
 * decision history is `activity_logs`, reached through the audit surface, not
 * duplicated here.
 *
 * @mixin WorkSupportRequest
 */
class WorkSupportRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_undecided' => $this->status->isUndecided(),
            'is_terminal' => $this->status->isTerminal(),

            'explanation' => $this->explanation,
            'submitted_at' => $this->created_at?->toIso8601String(),

            // Who asked. Present for the technician's own request too — it is
            // their name, and hiding it would make the shape role-dependent.
            'technician' => $this->technician !== null ? [
                'id' => $this->technician->uuid,
                'name' => $this->technician->fullName(),
            ] : null,

            // The job context an administrator needs to decide (FR-WSR-005).
            'pc_unit' => $this->pcUnit !== null ? [
                'id' => $this->pcUnit->uuid,
                'unit_code' => $this->pcUnit->unit_code,
                'pc_name' => $this->pcUnit->pc_name,
                'room' => $this->pcUnit->room?->name,
            ] : null,

            'maintenance' => $this->maintenanceRecord !== null ? [
                'id' => $this->maintenanceRecord->uuid,
                'title' => $this->maintenanceRecord->title,
                'status' => $this->maintenanceRecord->status->value,
                'status_label' => $this->maintenanceRecord->status->label(),
                'scheduled_for' => $this->maintenanceRecord->scheduled_for?->toIso8601String(),
            ] : null,

            'ticket' => $this->ticket !== null ? [
                'id' => $this->ticket->uuid,
                'number' => $this->ticket->ticket_number,
                'title' => $this->ticket->title,
            ] : null,

            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items
                    ->map(static fn (WorkSupportRequestItem $item): array => [
                        'name' => $item->displayName(),
                        'from_catalog' => $item->hardware_model_id !== null,
                        'quantity' => $item->quantity,
                        'remarks' => $item->remarks,
                    ])
                    ->values()
                    ->all(),
                [],
            ),

            'attachments' => $this->whenLoaded(
                'attachments',
                fn () => $this->attachments
                    ->map(static fn (WorkSupportRequestAttachment $file): array => [
                        'id' => $file->uuid,
                        'filename' => $file->original_filename,
                        // Derived from the **stored, server-detected** type, so
                        // a document cannot present itself as an image.
                        'kind' => AttachmentSecurity::kindFor($file->mime_type),
                        'size' => $file->file_size,
                        'caption' => $file->caption,
                    ])
                    ->values()
                    ->all(),
                [],
            ),

            /*
             * The decision, whichever one was taken. All three sets of fields
             * appear together rather than behind a status switch: the client
             * renders what is present, and a request that was asked about
             * face-to-face and *then* declined shows both — which is exactly
             * FR-WSR-004's "no prior workflow state overwritten in place",
             * visible rather than merely stored.
             */
            'decision' => [
                'by' => $this->decidedBy !== null ? [
                    'id' => $this->decidedBy->uuid,
                    'name' => $this->decidedBy->fullName(),
                ] : null,
                'at' => $this->decided_at?->toIso8601String(),

                'rescheduled_to' => $this->rescheduled_to?->toIso8601String(),
                'reschedule_reason' => $this->reschedule_reason,
                'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),

                'clarification_reason' => $this->clarification_reason,
                'proposed_meeting_at' => $this->proposed_meeting_at?->toIso8601String(),

                'decline_reason' => $this->decline_reason,

                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'cancellation_note' => $this->cancellation_note,
                'cancelled_by' => $this->cancelledBy?->fullName(),

                'closed_at' => $this->closed_at?->toIso8601String(),
            ],
        ];
    }
}
