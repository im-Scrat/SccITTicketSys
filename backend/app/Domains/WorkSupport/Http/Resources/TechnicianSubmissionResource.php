<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Resources;

use App\Domains\WorkSupport\Services\TechnicianSubmissions;
use App\Models\MaintenanceRecord;
use App\Models\RepairImage;
use App\Models\WorkSupportRequest;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One entry in a technician's submission history (SRS FR-WSR-009).
 *
 * ── Two kinds, one envelope ────────────────────────────────────────────────
 *
 * `kind` is the discriminator and it is always present, so the client renders a
 * proof of work and a support request as the different things they are rather
 * than inferring it from which fields happen to be null. The support-request
 * body reuses {@see WorkSupportRequestResource} verbatim — one shape for that
 * entity everywhere it appears.
 *
 * ── The proof-of-work body is deliberately narrow ──────────────────────────
 *
 * It is **not** `MaintenanceDetailResource`. That resource is built for the
 * maintenance page and carries cost, labour hours, the full actor set and
 * administrative fields; surfacing it here would hand a technician a wider
 * projection through a tracking list than the scan panel that produced the
 * submission ever gave them, which is the DD-49 argument undone through a side
 * door. Following DD-41, this shape simply **has no field** for any of it.
 *
 * What it carries is what a technician needs to answer "did my submission
 * save, and what happened to that job?" — the machine, the job, its status, the
 * resolution they wrote, and how many photographs are on file.
 *
 * There is no `@mixin` here, unlike the other resources in this domain: the
 * wrapped value is the service's envelope array rather than a model, so nothing
 * is proxied and the shape is read explicitly in {@see toArray()}.
 */
class TechnicianSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{kind: string, submitted_at: string|null, record: MaintenanceRecord|WorkSupportRequest} $entry */
        $entry = $this->resource;

        return [
            'kind' => $entry['kind'],
            'submitted_at' => $this->iso($entry['submitted_at']),
            ...($entry['kind'] === TechnicianSubmissions::KIND_PROOF
                ? ['proof_of_work' => $this->proof($entry['record'])]
                : ['support_request' => (new WorkSupportRequestResource($entry['record']))->toArray($request)]),
        ];
    }

    /**
     * The proof-of-work body.
     *
     * @return array<string, mixed>
     */
    private function proof(mixed $record): array
    {
        if (! $record instanceof MaintenanceRecord) {
            // Unreachable through the service, which pairs each kind with its
            // own model — but returning an empty body is the safe answer if a
            // future caller ever pairs them wrongly, rather than a type error
            // in a response.
            return [];
        }

        return [
            'id' => $record->uuid,
            'title' => $record->title,
            'type' => $record->type?->name,
            'status' => $record->status->value,
            'status_label' => $record->status->label(),

            // The same identity fields the scan panel shows, and no more — no
            // serial number, asset tag, hostname or address (OD-7,
            // `ScannedPcUnitResource`).
            'pc_unit' => $record->pcUnit !== null ? [
                'id' => $record->pcUnit->uuid,
                'unit_code' => $record->pcUnit->unit_code,
                'pc_name' => $record->pcUnit->pc_name,
            ] : null,

            'ticket' => $record->ticket?->ticket_number,

            // What the technician themselves wrote.
            'resolution' => $record->resolution,

            'started_at' => $record->started_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),

            /*
             * Evidence is summarised, not linked. The bytes live behind the
             * maintenance module's own authorized download route, which already
             * re-checks that record's policy on every request; publishing a
             * second way in from here would be a second boundary.
             */
            'evidence' => $record->relationLoaded('images')
                ? $record->images
                    ->map(static fn (RepairImage $image): array => [
                        'stage' => $image->image_type->value,
                        'filename' => $image->original_filename,
                        'kind' => AttachmentSecurity::kindFor($image->mime_type),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function iso(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        // The union hands timestamps back as driver strings; normalising here
        // keeps every date in this API in one format.
        return Carbon::parse($timestamp)->toIso8601String();
    }
}
