<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers;

use App\Domains\Assets\Actions\RecordQrScan;
use App\Domains\Assets\DTOs\QrScanOutcome;
use App\Domains\Assets\Http\Requests\ScanQrCodeRequest;
use App\Domains\Assets\Http\Resources\ScannedPcUnitResource;
use App\Domains\Assets\Services\QrScanResolver;
use App\Domains\Assets\Services\ScannedPcAccess;
use App\Enums\QrStatus;
use App\Enums\ScanResult;
use App\Http\Controllers\Controller;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The scanned entry point (SRS FR-QR-005/006/009/010/011/013; SDD §24, §35.4,
 * DD-47/DD-48).
 *
 * A printed label carries `{APP_URL}/qr/{code}`, so a phone camera opens the SPA
 * with no native app (FR-QR-009). The SPA lands on `/qr/:code` and calls this
 * endpoint. **The route is the entry point; it is not the authorization.**
 *
 * ── The three answers, and why they are so thin ────────────────────────────
 *
 * This endpoint returns a routing decision and nothing else. It never carries a
 * name, code, location, status, specification, ticket or maintenance detail —
 * not even to an authorized caller, who gets that from the panel endpoint after
 * a second authorization check. A scan resolves *where to go next*; the panel
 * decides *what may be seen* (FR-QR-010, DD-49).
 *
 *   `sign-in`  — no session. Returned for **every** classification, so an
 *                unauthenticated caller cannot tell a real code from an unknown
 *                one from a revoked one (FR-QR-013, AC-QR-010).
 *   `refused`  — authenticated but not entitled, or the label is no longer
 *                usable.
 *   `panel`    — proceed, with the scan's public id for the later proof-of-work
 *                submission (FR-MNT-012).
 *
 * ── Why the precise reason is rationed ─────────────────────────────────────
 *
 * A caller who clears the permission floor is staff doing maintenance, and
 * telling them "this label was revoked" is the difference between a technician
 * who fetches a new sticker and one who assumes the system is broken. A caller
 * who does not clear it gets one flat refusal for every cause — otherwise the
 * endpoint would answer "does this code exist?" for anyone with any account,
 * which is the enumeration oracle FR-QR-013 forbids in a different costume.
 *
 * ── Rate limiting comes first, deliberately ────────────────────────────────
 *
 * `throttle:qr-scan` runs ahead of this controller, so a throttled burst is
 * **not** written to `qr_scan_logs`. That is the intended trade: FR-QR-005's
 * "record every scan attempt" is about attempts the application actually
 * processes, and logging a rejected flood would turn an enumeration attempt into
 * a way to fill the audit table. The throttle is per client **and** per account
 * (FR-QR-013, NFR-SEC-009).
 */
class QrScanController extends Controller
{
    public function __construct(
        private readonly QrScanResolver $resolver,
        private readonly RecordQrScan $recorder,
        private readonly ScannedPcAccess $access,
    ) {}

    /**
     * Resolve a scanned code, log the attempt, and say what happens next.
     *
     * The order is fixed and load-bearing: **resolve, log, then decide**. The log
     * is written before any authorization question is asked and regardless of
     * its answer, which is what keeps it honest about attempts that were refused
     * (DD-47).
     */
    public function scan(ScanQrCodeRequest $request, string $code): JsonResponse
    {
        $outcome = $this->resolver->resolve($code);

        /** @var User|null $scanner */
        $scanner = $request->user();

        $log = $this->recorder->handle($outcome, $scanner, $request, $request->geolocation());

        // FR-QR-010: an unauthenticated scan discloses nothing and offers no
        // action. The classification went to the log a line ago; it does not go
        // into this response, and the response is byte-identical whatever the
        // code turned out to be.
        if ($scanner === null) {
            return response()->json(['next' => 'sign-in']);
        }

        // No maintenance or ticket entitlement: one flat refusal for every
        // cause, so an account with any login cannot use this endpoint to ask
        // "does this code exist?" (FR-QR-013). Nothing below runs.
        if (! $this->access->hasFloor($scanner)) {
            return $this->refused('not_authorized', 'You do not have access to this equipment workflow.');
        }

        if (! $outcome->isSuccess()) {
            return $this->refused(...$this->explain($outcome));
        }

        // Assets carry labels too (FR-QR-001), but WP-2.6b's workflow is the
        // technician's PC job: there is no scan-scoped panel for a standalone
        // asset, so an asset label resolves and logs `success` and then stops
        // here rather than pretending a destination exists.
        $pcUnit = $outcome->pcUnit();

        if ($pcUnit === null) {
            return $this->refused('no_workflow', 'This label is not for a computer unit.');
        }

        /*
         * The final authorization (FR-QR-012, AC-QR-012, DD-49). Clearing the
         * floor says the caller does maintenance work somewhere; this says they
         * do it *here*. The scan contributed nothing to the decision — the same
         * answer would be given had they typed the URL.
         */
        if ($scanner->cannot('viewScanned', $pcUnit)) {
            return $this->refused(
                'not_reachable',
                'You have no assigned work on this equipment.',
            );
        }

        return response()->json([
            'next' => 'panel',
            // The handle the proof-of-work submission will quote, so the write
            // is idempotent on this one physical scan (FR-MNT-012, DD-50).
            'scan_id' => $log->uuid,
            // Echoed so the client rebuilds the destination from a server-vouched
            // value rather than from whatever was in the address bar. It is the
            // code the caller already holds — no disclosure (DD-48).
            'code' => $outcome->qrCode?->code,
        ]);
    }

    /**
     * The scan-scoped PC panel (SRS FR-QR-012; SDD DD-49, §24).
     *
     * Reached after sign-in, at `/app/qr/{code}` in the SPA. The code is
     * resolved server-side again — the client's earlier scan result is not
     * carried forward as a claim, because a claim is exactly what DD-48 keeps
     * out of this flow.
     *
     * **Direct access is not a bypass, and is not treated as one.** A caller who
     * types this URL without ever scanning gets precisely the same answer as one
     * who scanned, because the scan never contributed to the decision
     * (FR-QR-013: "a code shall never be accepted as proof that the scanner is
     * standing in front of the machine"). There is nothing to bypass.
     *
     * No `qr_scan_logs` row is written here. The scan was logged at
     * `/scan`; logging the panel read as well would double-count a single
     * physical event and make the scan history lie about how often labels are
     * actually scanned.
     */
    public function panel(Request $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $outcome = $this->resolver->resolve($code);
        $pcUnit = $outcome->pcUnit();

        /*
         * Order matters. The floor is checked first so a caller without
         * maintenance entitlement gets the same flat refusal for an unknown
         * code, a revoked one and a real one — the `/scan` non-disclosure rule,
         * restated here rather than assumed, because this endpoint is reachable
         * on its own.
         */
        if (! $this->access->hasFloor($user)) {
            return $this->refused('not_authorized', 'You do not have access to this equipment workflow.');
        }

        if (! $outcome->isSuccess()) {
            return $this->refused(...$this->explain($outcome));
        }

        if ($pcUnit === null) {
            return $this->refused('no_workflow', 'This label is not for a computer unit.');
        }

        // The one authorization that matters, and the same one `/scan` asked:
        // is this machine reachable through work this person actually holds?
        $this->authorize('viewScanned', $pcUnit);

        return (new ScannedPcUnitResource($this->loadPanel($pcUnit, $user)))->response();
    }

    /* ----------------------------------------------------------- internals */

    /**
     * Load exactly what the panel renders, already scoped.
     *
     * The ticket and maintenance constraints come from {@see ScannedPcAccess},
     * the same service that authorized the request — so the panel shows the work
     * that granted access and never a superset. Scoping here rather than inside
     * {@see ScannedPcUnitResource} keeps the authorization decision out of a
     * presentation class, where nobody would think to test it.
     *
     * Installed components are limited to those still fitted (`removal_date`
     * null): a technician needs what is in the box now, not its history.
     */
    private function loadPanel(PcUnit $pcUnit, User $user): PcUnit
    {
        return $pcUnit->load([
            'room.floor.building',
            'specification',
            'componentInstallations' => fn ($query) => $query
                ->whereNull('removal_date')
                ->with('asset.hardwareModel.component'),
            /*
             * `getQuery()` unwraps the eager-load Relation to the Eloquent
             * builder underneath. The scoping services are typed to Builder on
             * purpose — they are the same methods the standalone queries use,
             * and widening them to accept a Relation would let a caller pass
             * something whose constraints behave differently.
             */
            'tickets' => function ($query) use ($user): void {
                $this->access->scopeRelevantTickets($query->getQuery(), $user);
                $query->with(['status:id,name', 'priority:id,name']);
            },
            'maintenanceRecords' => function ($query) use ($user): void {
                $this->access->scopeRelevantMaintenance($query->getQuery(), $user);
                $query->with(['type:id,name,slug', 'checklists']);
            },
            'qrCodes' => fn ($query) => $query->where('status', QrStatus::Active->value),
        ]);
    }

    /**
     * Turn a non-success outcome into a reason a maintenance-entitled caller can
     * act on.
     *
     * @return array{0: string, 1: string}
     */
    private function explain(QrScanOutcome $outcome): array
    {
        return match ($outcome->result) {
            ScanResult::Expired => [
                'label_inactive',
                'This label is no longer valid. Ask an administrator for a replacement.',
            ],
            default => [
                'label_unknown',
                'This label is not recognised.',
            ],
        };
    }

    private function refused(string $reason, string $message): JsonResponse
    {
        return response()->json([
            'next' => 'refused',
            'reason' => $reason,
            'message' => $message,
        ], 403);
    }
}
