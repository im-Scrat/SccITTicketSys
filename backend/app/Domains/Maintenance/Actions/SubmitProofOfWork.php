<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Maintenance\DTOs\ProofOfWorkResult;
use App\Domains\Maintenance\Exceptions\WorkTargetSelectionRequired;
use App\Domains\Maintenance\Services\MaintenanceLifecycle;
use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Domains\Maintenance\Services\ScannedWorkTargets;
use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Enums\RepairImageType;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\QrScanLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **Proof that work was performed on a scanned machine**
 * (SRS FR-MNT-009/010/012, FR-QR-008; SDD DD-50).
 *
 * The one write path of the scanned technician workflow, and deliberately a
 * thin one: every effect below belongs to a WP-2.6 component that is *called*,
 * never reimplemented.
 *
 *   evidence        -> {@see AttachRepairImage} (AttachmentSecurity::PROFILE_MAINTENANCE)
 *   narrative       -> {@see UpdateMaintenanceRecord} (audited field diff)
 *   status          -> {@see MaintenanceLifecycle::transition()} (gates, PC effect, audit)
 *   who may write   -> {@see MaintenanceVisibility::canWork()}
 *   which record    -> {@see ScannedWorkTargets} (which composes the two above)
 *
 * There is **no completion logic here.** A submission may *ask* to complete;
 * whether it may is decided by `MaintenanceLifecycle`, whose gates — required
 * checklist items, a resolution, evidence for non-preventive types — apply
 * unchanged and are the same wall the maintenance module's own button hits.
 *
 * ── The scan is the idempotency key, not the request ───────────────────────
 *
 * FR-MNT-012 asks for idempotency **on the scan**: "a repeated scan of the same
 * code, a resubmission of the same scan, or a repeat submission against the
 * same active record shall update that record rather than create a second one".
 * A scan is a single physical event; an HTTP request is not. So the layers are:
 *
 *   L1  `qr_scan_logs.uuid` gives the client a stable handle on that event.
 *   L2  `qr_scan_logs.maintenance_record_id` is **one nullable FK, set once**.
 *       One scan therefore cannot describe two jobs — structurally, not by
 *       agreement. A second submission quoting the same scan is a *replay*: it
 *       re-targets the bound record.
 *   L3  The scan row is taken `FOR UPDATE` for the whole transaction, so a
 *       double-tap, a retry or two parallel requests serialize; the loser reads
 *       the binding the winner wrote and replays onto it.
 *   L4  A **transaction-scoped advisory lock on (machine, technician)** closes
 *       the one race L3 cannot: two *different* scans of the same label,
 *       seconds apart, both finding no record and both creating one. L3 locks
 *       different rows in that case and would not serialize them.
 *   L4b Evidence is deduplicated on (record, SHA-256, stage), so the retry that
 *       re-uploads the same photographs adds nothing the second time.
 *   L5  {@see MaintenanceLifecycle::transition()} already returns early when the
 *       record is in the requested status, so a repeated "complete" writes no
 *       second audit row.
 *
 * A unique index on "one open record per machine" is deliberately **not** the
 * mechanism: `ConcurrentMaintenanceFinder` records the WP-2.6 client decision
 * that a machine may legitimately carry a scheduled preventive visit and an
 * active corrective repair at once. L2 and L4 give the database-enforced
 * guarantee where it is well posed — per scan, and per machine-and-technician —
 * without contradicting that.
 *
 * ── Creating a record is the exception, never the default ──────────────────
 *
 * FR-MNT-009 is explicit: the submission attaches to an existing active record,
 * "that is the primary and default path", and "the scan shall not by itself
 * confer permission to create maintenance work". Creation happens here only
 * when there is genuinely nothing to attach to **and** the actor independently
 * holds `maintenance.create` — the permission grants it, the scan never does.
 * Otherwise the submission is refused and nothing is written (AC-MNT-009).
 */
class SubmitProofOfWork
{
    /**
     * The outcomes a submission may ask for.
     *
     * A subset of {@see MaintenanceStatus}, and not a new enum: these are
     * lifecycle states, and inventing a parallel vocabulary for them would give
     * the two somewhere to disagree. `scheduled` is excluded because proof of
     * work asserts work happened, and `cancelled` because calling a job off is
     * not something you evidence with photographs of having done it.
     *
     * @var list<string>
     */
    public const OUTCOMES = ['in_progress', 'on_hold', 'completed'];

    public function __construct(
        private readonly ScannedWorkTargets $targets,
        private readonly MaintenanceVisibility $visibility,
        private readonly MaintenanceLifecycle $lifecycle,
        private readonly CreateMaintenanceRecord $create,
        private readonly UpdateMaintenanceRecord $update,
        private readonly AttachRepairImage $attach,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     * @param  list<UploadedFile>  $files  evidence for this submission
     *
     * @throws ValidationException
     * @throws WorkTargetSelectionRequired
     */
    public function handle(
        QrScanLog $scan,
        PcUnit $pcUnit,
        User $actor,
        array $data,
        array $files,
        ?Request $request = null,
    ): ProofOfWorkResult {
        return DB::transaction(function () use ($scan, $pcUnit, $actor, $data, $files, $request): ProofOfWorkResult {
            // L3. Re-read the scan under a row lock: everything after this point
            // is serialized against any other submission quoting the same scan,
            // and the binding we read is the committed one, not the one this
            // request happened to see a moment ago.
            /** @var QrScanLog $locked */
            $locked = QrScanLog::query()->whereKey($scan->getKey())->lockForUpdate()->firstOrFail();

            // L4. Serialize resolve-or-create for this machine and this person.
            $this->lockWorkspace($pcUnit, $actor);

            [$record, $created, $replayed] = $this->resolveTarget($locked, $pcUnit, $actor, $data, $request);

            // Evidence first, and deliberately so: the completion gate for a
            // corrective or hardware-upgrade job counts repair images, and it
            // must count the ones this submission brought.
            [$added, $skipped] = $this->storeEvidence($record, $files, $data, $actor, $request);

            $this->assertEvidenced($record);

            $record = $this->applyWork($record, $actor, $data, $request);

            // L2. Bound once. A replay finds it already set and leaves it alone,
            // which is what makes "one scan, one job" a property of the column
            // rather than of this method remembering to behave.
            if ($locked->maintenance_record_id === null) {
                $locked->forceFill(['maintenance_record_id' => $record->getKey()])->save();
            }

            $this->audit->activity(
                ActivityAction::MaintenanceProofSubmitted,
                actor: $actor,
                subject: $record,
                properties: array_filter([
                    'scan' => $locked->uuid,
                    'pc_unit' => $pcUnit->unit_code,
                    'outcome' => $record->status->value,
                    'evidence_added' => $added,
                    'evidence_skipped' => $skipped > 0 ? $skipped : null,
                    'record_created' => $created ?: null,
                    'replay' => $replayed ?: null,
                ], static fn (mixed $value): bool => $value !== null),
                request: $request,
                module: 'maintenance',
                description: "Proof of work submitted for {$record->title}",
            );

            return new ProofOfWorkResult($record->refresh(), $created, $replayed, $added, $skipped);
        });
    }

    /* ----------------------------------------------------------- target */

    /**
     * Decide which record this proof belongs to.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: MaintenanceRecord, 1: bool, 2: bool}
     *
     * @throws ValidationException
     * @throws WorkTargetSelectionRequired
     */
    private function resolveTarget(
        QrScanLog $scan,
        PcUnit $pcUnit,
        User $actor,
        array $data,
        ?Request $request,
    ): array {
        $named = is_string($data['maintenance_id'] ?? null) ? $data['maintenance_id'] : null;

        // ── Replay ─────────────────────────────────────────────────────────
        if ($scan->maintenance_record_id !== null) {
            $record = MaintenanceRecord::query()
                ->whereKey($scan->maintenance_record_id)
                ->lockForUpdate()
                ->first();

            /*
             * The binding is not a standing permission. Between the first
             * submission and this one the record may have been completed,
             * cancelled, archived or reassigned — so the same write rule is
             * asked again, exactly as it was the first time.
             */
            if ($record === null || ! $this->visibility->canWork($actor, $record)) {
                throw ValidationException::withMessages([
                    'scan_id' => 'The maintenance record this scan was submitted against is no longer open to you. Scan the label again to start a new submission.',
                ])->status(422);
            }

            /*
             * A replay that names a *different* record is refused rather than
             * quietly re-pointed: one scan describes one job (FR-MNT-012), and
             * silently moving the binding would be the duplicate this
             * requirement exists to prevent, wearing a different hat.
             */
            if ($named !== null && $named !== $record->uuid) {
                throw ValidationException::withMessages([
                    'maintenance_id' => 'This scan has already been submitted against a different maintenance record. Scan the label again to work on another job.',
                ])->status(422);
            }

            return [$record, false, true];
        }

        // ── Explicit selection ─────────────────────────────────────────────
        if ($named !== null) {
            $record = $this->targets->find($pcUnit, $actor, $named, lock: true);

            if ($record === null) {
                /*
                 * One answer for "no such record", "another technician's",
                 * "another machine's" and "already closed". Distinguishing them
                 * would turn this endpoint into the oracle FR-QR-013 forbids.
                 */
                throw ValidationException::withMessages([
                    'maintenance_id' => 'That maintenance record is not one you can work on this unit.',
                ])->status(422);
            }

            return [$record, false, false];
        }

        // ── Unambiguous, or refuse to guess ────────────────────────────────
        $candidates = $this->targets->candidates($pcUnit, $actor);

        if ($candidates->count() > 1) {
            /*
             * Client decision, 2026-08-29: "do not guess; require explicit
             * technician selection". Picking the newest, or the one scheduled
             * soonest, would be a guess with a technician's name on it.
             */
            throw new WorkTargetSelectionRequired($candidates);
        }

        $record = $candidates->first();

        if ($record !== null) {
            return [$record, false, false];
        }

        return [$this->openRecord($pcUnit, $actor, $data, $request), true, false];
    }

    /**
     * FR-MNT-009's narrow exception: nothing to attach to, so open a record —
     * **only** if the actor may create maintenance work in their own right.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function openRecord(PcUnit $pcUnit, User $actor, array $data, ?Request $request): MaintenanceRecord
    {
        if ($actor->cannot('create', MaintenanceRecord::class)) {
            throw ValidationException::withMessages([
                'maintenance_id' => 'This unit has no open maintenance record you can work on, and you cannot open one. Ask an administrator to schedule the work.',
            ])->status(422);
        }

        /*
         * Linked to the originating ticket "when the work arose from an
         * assignment" (FR-MNT-009) — and only when that is unambiguous. With two
         * active assignments on one machine the honest link is none: a wrong
         * ticket reference is worse than a missing one, and the same "do not
         * guess" rule applies here as to record selection.
         */
        $tickets = $this->targets->relevantTickets($pcUnit, $actor);

        $result = $this->create->handle([
            'title' => 'On-site work: '.$pcUnit->unit_code,
            // Corrective is the type the scanned workflow describes: someone is
            // standing at a machine doing unscheduled repair work.
            'type' => 'corrective',
            'pc_unit' => $pcUnit->uuid,
            'ticket' => $tickets->count() === 1 ? $tickets->first()?->uuid : null,
            'diagnosis' => $data['diagnosis'] ?? null,
        ], $actor, $request);

        return $result['record'];
    }

    /* --------------------------------------------------------- evidence */

    /**
     * Store this submission's evidence through the single attachment boundary.
     *
     * @param  list<UploadedFile>  $files
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int} added, skipped-as-duplicate
     */
    private function storeEvidence(
        MaintenanceRecord $record,
        array $files,
        array $data,
        User $actor,
        ?Request $request,
    ): array {
        if ($files === []) {
            return [0, 0];
        }

        $type = RepairImageType::from((string) ($data['evidence_type'] ?? RepairImageType::After->value));
        $caption = is_string($data['caption'] ?? null) && $data['caption'] !== '' ? $data['caption'] : null;

        $added = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $checksum = hash_file('sha256', (string) $file->getRealPath());

            /*
             * L4b. The same bytes, in the same stage, already on this record is
             * the double-submit case — the technician's thumb, or the browser's
             * retry, not a second photograph. Checked before the file is
             * written, so a replay costs no storage.
             *
             * Keyed on the stage as well as the bytes on purpose: the identical
             * image filed as `before` and again as `after` is two deliberate
             * assertions about one machine, and collapsing them would discard a
             * technician's meaning rather than a duplicate.
             */
            if ($checksum !== false && $record->images()
                ->where('checksum', $checksum)
                ->where('image_type', $type->value)
                ->exists()
            ) {
                $skipped++;

                continue;
            }

            $this->attach->handle($record, $file, $type, $caption, $actor, $request);
            $added++;
        }

        return [$added, $skipped];
    }

    /**
     * FR-MNT-010: a proof-of-work submission carries evidence.
     *
     * Asserted on the **record**, not on the request, so the retry whose files
     * were all recognised as duplicates still succeeds — the evidence it is
     * proof of is demonstrably there. A first submission that brings none, to a
     * record that has none, is refused with nothing written (AC-MNT-009): this
     * runs inside the caller's transaction, so the refusal rolls back the
     * record it may just have opened.
     *
     * @throws ValidationException
     */
    private function assertEvidenced(MaintenanceRecord $record): void
    {
        if ($record->images()->count() > 0) {
            return;
        }

        throw ValidationException::withMessages([
            'evidence' => 'Attach at least one photograph of the work before submitting proof.',
        ])->status(422);
    }

    /* ----------------------------------------------------------- effect */

    /**
     * Write the narrative and move the record to the requested outcome.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyWork(MaintenanceRecord $record, User $actor, array $data, ?Request $request): MaintenanceRecord
    {
        /*
         * Proof of work asserts that work happened, so a record still merely
         * `scheduled` is started first — through the lifecycle, so `started_at`
         * is stamped once and the machine moves to `under_maintenance` with its
         * previous status remembered, exactly as it would had the technician
         * pressed Start in the maintenance module. Writing `in_progress`
         * directly here would skip both effects and the audit row with them.
         */
        if ($record->status === MaintenanceStatus::Scheduled) {
            $record = $this->lifecycle->transition($record, MaintenanceStatus::InProgress, $actor, [], $request);
        }

        $fields = ['resolution' => (string) $data['resolution']];

        foreach (['diagnosis', 'root_cause'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $fields[$key] = $data[$key];
            }
        }

        $record = $this->update->handle($record, $fields, $actor, $request);

        $outcome = MaintenanceStatus::from((string) $data['outcome']);

        /*
         * L5. `transition()` is a no-op when the record is already there, so a
         * resubmitted outcome writes no second audit row. An *illegal* one
         * raises 422 from the lifecycle — this method does not second-guess it.
         */
        return $this->lifecycle->transition($record, $outcome, $actor, [], $request);
    }

    /**
     * L4 — a transaction-scoped advisory lock on (machine, technician).
     *
     * Released automatically at commit or rollback, so there is nothing to leak
     * and no cleanup path to forget. The key is a composition of two ids rather
     * than a hash: a collision would cost one unrelated pair a moment's wait and
     * nothing else, while a missed lock would cost a duplicate record.
     *
     * Postgres-only by construction, which this application is (SDD §5). Guarded
     * anyway so a future sqlite-backed harness degrades to L2 and L3 rather than
     * erroring on an unknown function.
     */
    private function lockWorkspace(PcUnit $pcUnit, User $actor): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $key = ((int) $pcUnit->getKey() << 32) | ((int) $actor->getKey() & 0xFFFFFFFF);

        $connection->statement('select pg_advisory_xact_lock(?)', [$key]);
    }
}
