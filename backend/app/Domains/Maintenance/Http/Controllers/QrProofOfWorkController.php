<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Assets\Services\QrScanResolver;
use App\Domains\Assets\Services\ScannedPcAccess;
use App\Domains\Maintenance\Actions\SubmitProofOfWork;
use App\Domains\Maintenance\Exceptions\WorkTargetSelectionRequired;
use App\Domains\Maintenance\Http\Requests\SubmitProofOfWorkRequest;
use App\Domains\Maintenance\Http\Resources\ProofOfWorkResource;
use App\Domains\Maintenance\Services\ScannedWorkTargets;
use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\QrScanLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Proof of work from the scanned workflow (SRS FR-MNT-009/010/012, FR-QR-008;
 * SDD DD-50).
 *
 * Lives in **Maintenance**, not Assets, although its URL begins `/qr`. Every
 * effect it has is a maintenance effect — a record, its evidence, its status —
 * and the domain folders in this codebase organise by business capability, not
 * by route prefix. The QR code is the entry identifier, and `QrScanResolver` is
 * borrowed for exactly that: turning a printed string into a machine.
 *
 * ── The scan identifies; it never authorizes ───────────────────────────────
 *
 * Four separate things must hold before a byte is written, and the scan is not
 * one of them:
 *
 *  1. `can:maintenance.update` at the route — may you do maintenance at all?
 *  2. `PcUnitPolicy::viewScanned` — Stage C's reachability predicate, unchanged
 *     and uncopied: do you have work on **this machine**?
 *  3. The quoted scan is yours, for this machine, and resolved successfully.
 *  4. `MaintenanceVisibility::canWork()` on the record itself, inside the
 *     action's transaction.
 *
 * Check 2 is the same call the panel makes, so a machine a technician cannot
 * open they equally cannot submit against — and check 3 cannot substitute for
 * it. A caller holding a valid scan id for a machine they have since stopped
 * working on is refused at 2, which is FR-QR-013's *"a code shall never be
 * accepted as proof that the scanner is standing in front of the machine"* in
 * its operative form.
 *
 * ── Refusals disclose nothing new ──────────────────────────────────────────
 *
 * The label reasons mirror `QrScanController` exactly, because a caller must
 * not be able to learn more about a code by POSTing to it than by scanning it.
 */
class QrProofOfWorkController extends Controller
{
    public function __construct(
        private readonly QrScanResolver $resolver,
        private readonly ScannedPcAccess $access,
        private readonly ScannedWorkTargets $targets,
        private readonly SubmitProofOfWork $action,
    ) {}

    /**
     * The jobs on this machine this technician may submit proof against.
     *
     * A read-only companion to {@see store()}, answering the "which record?"
     * question before the technician has filled anything in. It renders from
     * {@see ScannedWorkTargets} — the same service, and therefore the same
     * predicate, that the submission itself resolves through, so the chooser
     * can never offer a job the write would refuse.
     */
    public function index(Request $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pcUnit = $this->gate($user, $code);

        return response()->json([
            'data' => [
                'targets' => $this->describe($this->targets->candidates($pcUnit, $user)->all()),
                // Whether the "no open record" path would open one for this
                // caller (FR-MNT-009). The client uses it to decide between
                // "ask an administrator" and "record this as new work" — the
                // server re-decides it under lock either way.
                'may_open_record' => $user->can('create', MaintenanceRecord::class),
            ],
        ]);
    }

    /**
     * Submit proof of work against the scanned machine.
     */
    public function store(SubmitProofOfWorkRequest $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pcUnit = $this->gate($user, $code);
        $scan = $this->resolveScan($request->validated('scan_id'), $user, $pcUnit);

        try {
            $result = $this->action->handle(
                $scan,
                $pcUnit,
                $user,
                $request->validated(),
                $request->evidenceFiles(),
                $request,
            );
        } catch (WorkTargetSelectionRequired $selection) {
            /*
             * 422 with the candidates attached. Not a 409: from the client's
             * point of view this is a field that must be filled in, and it is
             * `maintenance_id` — rendering it as a validation error puts the
             * message where the chooser already is.
             */
            return response()->json([
                'message' => 'More than one maintenance record is open for this unit. Choose which one this work belongs to.',
                'errors' => ['maintenance_id' => ['Choose which maintenance record this work belongs to.']],
                'targets' => $this->describe($selection->candidates->all()),
            ], 422);
        }

        // Loaded onto the record the result already holds rather than rebuilt
        // into a second DTO: the resource renders relations, and swapping the
        // object out would be two things that have to stay in step.
        $result->record->load(['type:id,name,slug', 'ticket:id,ticket_number', 'checklists', 'images']);

        return (new ProofOfWorkResource($result))
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }

    /* ----------------------------------------------------------- internals */

    /**
     * Resolve the code and apply the two gates that precede any record work.
     *
     * Returns the machine only when the caller could equally have opened its
     * panel; otherwise it aborts with the panel's own refusal, byte for byte.
     */
    private function gate(User $user, string $code): PcUnit
    {
        $outcome = $this->resolver->resolve($code);

        // Restated rather than assumed, exactly as the panel does: this endpoint
        // is reachable on its own, and a caller below the floor must get one
        // flat answer whatever the code turned out to be.
        if (! $this->access->hasFloor($user)) {
            $this->refuse('not_authorized', 'You do not have access to this equipment workflow.');
        }

        if ($outcome->result === ScanResult::Expired) {
            $this->refuse('label_inactive', 'This label is no longer valid. Ask an administrator for a replacement.');
        }

        if ($outcome->result !== ScanResult::Success) {
            $this->refuse('label_unknown', 'This label is not recognised.');
        }

        $pcUnit = $outcome->pcUnit();

        if ($pcUnit === null) {
            $this->refuse('no_workflow', 'This label is not for a computer unit.');
        }

        // Stage C's predicate, called and not copied. A second definition of
        // "may reach this machine" is how the write path and the read path drift
        // into an IDOR.
        $this->authorize('viewScanned', $pcUnit);

        return $pcUnit;
    }

    /**
     * The scan this proof claims to belong to.
     *
     * Four conditions, and the same refusal for failing any of them — an
     * attacker probing scan identifiers must not learn which one they got wrong:
     *
     *   - it exists;
     *   - it is **this caller's** (`scanned_by`), so a harvested identifier from
     *     someone else's session is worthless;
     *   - it is for **this machine**, so a scan of unit A cannot carry a
     *     submission against unit B;
     *   - it resolved successfully, so a revoked or unknown label's log entry
     *     cannot be used as a handle.
     *
     * @throws ValidationException
     */
    private function resolveScan(mixed $scanId, User $user, PcUnit $pcUnit): QrScanLog
    {
        $scan = QrScanLog::query()
            ->where('uuid', is_string($scanId) ? $scanId : '')
            ->where('scanned_by', $user->getKey())
            ->where('pc_unit_id', $pcUnit->getKey())
            ->where('scan_result', ScanResult::Success->value)
            ->first();

        if ($scan === null) {
            throw ValidationException::withMessages([
                'scan_id' => 'That scan is no longer valid. Scan the label again to continue.',
            ])->status(422);
        }

        return $scan;
    }

    /**
     * The chooser's shape — enough to tell two jobs apart and nothing more.
     *
     * No diagnosis, no resolution, no cost: these rows are already the caller's
     * own work, but a list is not a detail view, and widening it here would make
     * the chooser a second, unaudited projection of a maintenance record.
     *
     * @param  list<MaintenanceRecord>  $records
     * @return list<array<string, mixed>>
     */
    private function describe(array $records): array
    {
        return array_map(static fn (MaintenanceRecord $record): array => [
            'id' => $record->uuid,
            'title' => $record->title,
            'type' => $record->type?->name,
            'status' => $record->status->value,
            'status_label' => $record->status->label(),
            'scheduled_for' => $record->scheduled_for?->toIso8601String(),
        ], $records);
    }

    private function refuse(string $reason, string $message): never
    {
        abort(response()->json([
            'next' => 'refused',
            'reason' => $reason,
            'message' => $message,
        ], 403));
    }
}
