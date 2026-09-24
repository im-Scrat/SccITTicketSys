<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Resources;

use App\Domains\Maintenance\DTOs\ProofOfWorkResult;
use App\Models\MaintenanceChecklist;
use App\Models\RepairImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a proof-of-work submission did (SRS FR-MNT-009/010/012; SDD DD-50).
 *
 * ── A fourth projection, and why it is not `MaintenanceDetailResource` ─────
 *
 * The obvious move would be to answer with the module's own detail resource.
 * That resource is built for the maintenance *page*, and carries cost, labour
 * hours, the full actor set and administrative fields with it. Returning it
 * from the scanned workflow would quietly hand a technician standing at a
 * machine a wider projection than the panel that got them there — undoing
 * DD-49's whole argument through a side door.
 *
 * So this is deliberately narrow and, following DD-41, **has no field** for
 * anything the scan panel would not already have shown: no cost, no labour
 * hours, no custodian, no created-by, no ticket detail beyond its number.
 *
 * ── Why it reports what happened, not just the state ───────────────────────
 *
 * `created`, `replayed`, `evidence_added` and `evidence_skipped` exist because
 * the three outcomes of a submission are genuinely different events a
 * technician needs distinguished — "your photographs were added", "this was
 * already submitted, nothing was duplicated", "no record existed so one was
 * opened" — and inferring them client-side from a record's state would be
 * guesswork dressed as a confirmation.
 *
 * @mixin ProofOfWorkResult
 */
class ProofOfWorkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->record;

        return [
            // What the submission did.
            'created' => $this->created,
            'replayed' => $this->replayed,
            'evidence_added' => $this->evidenceAdded,
            'evidence_skipped' => $this->evidenceSkipped,

            // The job it landed on, in the panel's vocabulary.
            'maintenance' => [
                'id' => $record->uuid,
                'title' => $record->title,
                'type' => $record->type?->name,
                'status' => $record->status->value,
                'status_label' => $record->status->label(),
                'ticket' => $record->ticket?->ticket_number,
                'diagnosis' => $record->diagnosis,
                'root_cause' => $record->root_cause,
                'resolution' => $record->resolution,
                'started_at' => $record->started_at?->toIso8601String(),
                'completed_at' => $record->completed_at?->toIso8601String(),
                'checklist' => $record->checklists
                    ->map(static fn (MaintenanceChecklist $item): array => [
                        'id' => (string) $item->getKey(),
                        'label' => $item->item_label,
                        'is_required' => (bool) $item->is_required,
                        'is_completed' => (bool) $item->is_completed,
                    ])
                    ->values()
                    ->all(),
            ],

            // The evidence now standing on the record — the whole set, not only
            // this submission's, because that is what the technician is being
            // asked to confirm is complete.
            'evidence' => $record->images
                ->map(static fn (RepairImage $image): array => [
                    'id' => $image->uuid,
                    'stage' => $image->image_type->value,
                    'filename' => $image->original_filename,
                    'size' => $image->file_size,
                    'caption' => $image->caption,
                    'created_at' => $image->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
