<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Actions;

use App\Domains\Assets\Services\ScannedPcAccess;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Maintenance\Services\ScannedWorkTargets;
use App\Domains\WorkSupport\Events\WorkSupportRequestSubmitted;
use App\Enums\ActivityAction;
use App\Enums\WorkSupportStatus;
use App\Models\HardwareModel;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\User;
use App\Models\WorkSupportRequest;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submit a technician's request for help finishing a job
 * (SRS FR-WSR-001/002/003/011; SDD DD-51).
 *
 * ── Nothing the client sends identifies anything ───────────────────────────
 *
 * The machine comes from the **scanned code**, resolved server-side. The
 * technician comes from the **session**. The maintenance record is resolved
 * *through* {@see ScannedWorkTargets}, which is the same predicate that decides
 * which jobs the scan panel offers and which jobs proof of work may be
 * submitted against.
 *
 * So `technician_id`, `pc_unit_id`, `ticket_id` and `maintenance_record_id` are
 * all **derived, never accepted**. There is no field a caller could edit to
 * reach another machine, another technician's job, or another person's name on
 * a request — which is the concrete form of the Client's instruction that a
 * manipulated identifier must not become access.
 *
 * ── The three refusals FR-WSR-002 asks for ─────────────────────────────────
 *
 * No items, a blank explanation, or a machine/job the caller may not work: all
 * refused before a row exists. The first two are also enforced by database
 * CHECK constraints (DR-020), so a caller that is not this action — a command,
 * a job, a future import — meets the same wall.
 */
class SubmitWorkSupportRequest
{
    public function __construct(
        private readonly ScannedPcAccess $access,
        private readonly ScannedWorkTargets $targets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     * @param  list<UploadedFile>  $files  optional supporting evidence
     *
     * @throws ValidationException
     */
    public function handle(
        PcUnit $pcUnit,
        User $actor,
        array $data,
        array $files = [],
        ?Request $http = null,
    ): WorkSupportRequest {
        /*
         * Re-resolved here rather than trusted from the controller. The action
         * is the boundary a command or a test also crosses, and an authorization
         * that only exists in an HTTP path is one a future caller will skip.
         */
        if (! $this->access->canReach($actor, $pcUnit)) {
            throw ValidationException::withMessages([
                'pc_unit' => 'You have no assigned work on this equipment.',
            ])->status(422);
        }

        $record = $this->resolveMaintenance($pcUnit, $actor, $data);

        return DB::transaction(function () use ($pcUnit, $actor, $data, $files, $record, $http): WorkSupportRequest {
            $request = WorkSupportRequest::query()->create([
                'pc_unit_id' => $pcUnit->getKey(),
                'maintenance_record_id' => $record?->getKey(),
                // Taken from the job, never from the payload: the ticket a
                // request belongs to is the one its maintenance record already
                // records as the origin of the work.
                'ticket_id' => $record?->ticket_id,
                'technician_id' => $actor->getKey(),
                'explanation' => trim((string) $data['explanation']),
                // Born `submitted`. Every later move goes through the lifecycle.
                'status' => WorkSupportStatus::Submitted->value,
            ]);

            $this->storeItems($request, $data['items'] ?? []);
            $this->storeAttachments($request, $files, $actor, $data);

            $this->audit->activity(
                ActivityAction::WorkSupportRequestSubmitted,
                actor: $actor,
                subject: $request,
                properties: array_filter([
                    'pc_unit' => $pcUnit->unit_code,
                    'maintenance_record' => $record?->uuid,
                    'ticket' => $record?->ticket?->ticket_number,
                    'items' => $request->items()->count(),
                    'attachments' => $request->attachments()->count() ?: null,
                ], static fn (mixed $value): bool => $value !== null),
                request: $http,
                module: 'work-support',
                description: "Support request raised for {$pcUnit->unit_code}",
            );

            /*
             * WP-2.7a — the notification seam (FR-WSR-012, FR-NOT-003 T9).
             *
             * The half of FR-WSR-012 that WP-2.6b shipped without: the request
             * reached the administrator inbox and nothing announced it.
             *
             * Inside the transaction is safe by construction —
             * `NotificationDispatcher` defers delivery through
             * `DB::afterCommit()`, so a submission that rolls back tells nobody
             * anything, and a submission that commits tells the administrators
             * exactly once.
             */
            WorkSupportRequestSubmitted::dispatch($request, $actor);

            return $request->load(['items.hardwareModel', 'attachments', 'pcUnit', 'maintenanceRecord', 'technician']);
        });
    }

    /**
     * The job this request is about.
     *
     * FR-WSR-001 permits a request with no maintenance record — *"a request
     * unrelated to any ticket shall still be permitted, provided it names a PC
     * unit"* — so this may legitimately return null. What it may never do is
     * return a record the caller cannot work: when one is named it is resolved
     * through the shared predicate, and an unknown, another technician's,
     * another machine's or a closed record all give the **same** refusal.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function resolveMaintenance(PcUnit $pcUnit, User $actor, array $data): ?MaintenanceRecord
    {
        $named = is_string($data['maintenance_id'] ?? null) ? $data['maintenance_id'] : null;

        if ($named !== null) {
            $record = $this->targets->find($pcUnit, $actor, $named);

            if ($record === null) {
                throw ValidationException::withMessages([
                    'maintenance_id' => 'That maintenance record is not one you can work on this unit.',
                ])->status(422);
            }

            return $record;
        }

        // Unnamed: attach to the one open job if there is exactly one, and to
        // none otherwise. Picking between several would be the guess the Client
        // ruled out for proof of work, and the same reasoning holds here.
        $candidates = $this->targets->candidates($pcUnit, $actor);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * The line items (FR-WSR-002).
     *
     * A catalogue model **or** free text, quantity > 0 — both enforced by
     * database CHECKs as well as by the FormRequest. A catalogue id that does
     * not resolve degrades to its description rather than failing the whole
     * submission: the technician's words are the part that matters.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    private function storeItems(WorkSupportRequest $request, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Name at least one thing the job needs.',
            ])->status(422);
        }

        foreach ($items as $item) {
            /*
             * By integer id, not uuid. `hardware_models` is a **catalogue
             * lookup**, not a public record: it carries no uuid column, and
             * Phase 2.5 already references it this way through a validated
             * `Rule::exists` (`StoreAssetRequest`). Following the module's own
             * convention beats inventing a second one, and adding a uuid column
             * would be a schema change this stage is instructed not to make.
             */
            $model = isset($item['hardware_model'])
                ? HardwareModel::query()->find((int) $item['hardware_model'])
                : null;

            $description = trim((string) ($item['description'] ?? ''));

            $request->items()->create([
                'hardware_model_id' => $model?->getKey(),
                'description' => $description !== '' ? $description : null,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'remarks' => isset($item['remarks']) && trim((string) $item['remarks']) !== ''
                    ? trim((string) $item['remarks'])
                    : null,
            ]);
        }
    }

    /**
     * Optional supporting evidence, through the one attachment boundary
     * (FR-WSR-003, DD-53).
     *
     * `PROFILE_MAINTENANCE`, the same profile proof of work uses — no new
     * profile, no second upload path. Type detected from the bytes, checksum
     * stored, path composed here, private disk.
     *
     * @param  list<UploadedFile>  $files
     * @param  array<string, mixed>  $data
     */
    private function storeAttachments(WorkSupportRequest $request, array $files, User $actor, array $data): void
    {
        $caption = is_string($data['caption'] ?? null) && $data['caption'] !== '' ? $data['caption'] : null;

        foreach ($files as $file) {
            $mime = AttachmentSecurity::detect($file, AttachmentSecurity::PROFILE_MAINTENANCE);

            $request->attachments()->create([
                'uploaded_by' => $actor->getKey(),
                'kind' => AttachmentSecurity::kindFor($mime),
                'disk' => 'local',
                'storage_path' => $file->store("work-support/{$request->uuid}", 'local'),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'file_size' => $file->getSize(),
                'checksum' => hash_file('sha256', (string) $file->getRealPath()) ?: null,
                'caption' => $caption,
            ]);
        }
    }
}
