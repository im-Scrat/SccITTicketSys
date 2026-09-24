<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Assets\DTOs\QrScanOutcome;
use App\Enums\AssetStatus;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Enums\ScanResult;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\QrCode;

/**
 * Resolve a scanned code and classify what it turned out to be
 * (SRS FR-QR-005/006; SDD DD-47, §24).
 *
 * **Server-side, always.** FR-QR-005 requires the code to be verified and the
 * target resolved on the server; the scanned URL carries nothing but the code,
 * and nothing the caller sends is treated as a claim about what the code points
 * at. That is why this class takes a `string` and not a PC identifier — there is
 * no parameter here for a client to forge (DD-48).
 *
 * **Classification is not disclosure.** Everything below produces the precise
 * value that goes into `qr_scan_logs`. What the HTTP response says is decided
 * elsewhere and is deliberately coarser: an unauthenticated caller cannot tell
 * `invalid` from `expired` from `success` (FR-QR-013). Keeping the two apart is
 * the whole reason this class returns a {@see QrScanOutcome} rather than a
 * response.
 *
 * **A code is an identifier, never a credential** (DD-47). Resolving one grants
 * nothing: possession of a sticker anyone in the building can photograph must
 * not move the authorization needle, so this class answers "what is this?" and
 * never "may you?".
 */
class QrScanResolver
{
    /**
     * Longest code this will even look up.
     *
     * Issued codes are 13 characters (`PC-` + 10). The ceiling is generous
     * enough to survive a future format change and small enough that a scan
     * endpoint cannot be used to push megabytes through an indexed string
     * comparison. The route regex is the first line of this defence; this is the
     * second, because the resolver is also reachable from tests and commands.
     */
    private const MAX_CODE_LENGTH = 64;

    /**
     * Resolve and classify, in one pass (FR-QR-006).
     *
     *   `success`  — active code, resolvable live target
     *   `invalid`  — unknown code (or one that cannot be a code at all)
     *   `expired`  — code `inactive`/`revoked`, **or** target retired/disposed/archived
     *   `mismatch` — see {@see ScanResult::Mismatch}; unreachable by design
     *
     * `mismatch` is deliberately never produced. FR-QR-006 defines it as "code
     * resolves but points to a different/moved target **than claimed**", and
     * there is no claim: `qr_codes` binds its target directly and the scan URL
     * carries only the code. Manufacturing a caller-supplied "claimed target"
     * parameter purely to make the enum case reachable would reintroduce exactly
     * the client-supplied state DD-48 exists to keep out. The case stays
     * reserved against a future design that legitimately has a claim (Client
     * decision OD-6, 2026-08-29).
     */
    public function resolve(string $code): QrScanOutcome
    {
        $code = trim($code);

        if ($code === '' || strlen($code) > self::MAX_CODE_LENGTH) {
            return new QrScanOutcome(ScanResult::Invalid);
        }

        $qrCode = QrCode::query()->where('code', $code)->first();

        if ($qrCode === null) {
            return new QrScanOutcome(ScanResult::Invalid);
        }

        // The target is loaded `withTrashed()` on purpose: an archived machine
        // must classify as `expired` — a truthful "this label no longer points
        // at live equipment" — rather than as `invalid`, which would say the
        // sticker was never real. The distinction only ever reaches the log.
        $target = $this->targetFor($qrCode);

        if ($qrCode->status !== QrStatus::Active) {
            return new QrScanOutcome(ScanResult::Expired, $qrCode, $target);
        }

        if ($target === null || ! $this->targetIsLive($target)) {
            return new QrScanOutcome(ScanResult::Expired, $qrCode, $target);
        }

        return new QrScanOutcome(ScanResult::Success, $qrCode, $target);
    }

    /* ----------------------------------------------------------- internals */

    /**
     * The bound target, archived rows included.
     *
     * `qr_codes_target_check` guarantees exactly one of the two columns is set,
     * so this cannot return the wrong kind — the database has already made the
     * ambiguous case unrepresentable (FR-QR-001, BR-09).
     */
    private function targetFor(QrCode $qrCode): Asset|PcUnit|null
    {
        if ($qrCode->pc_unit_id !== null) {
            return PcUnit::withTrashed()->find($qrCode->pc_unit_id);
        }

        if ($qrCode->asset_id !== null) {
            return Asset::withTrashed()->find($qrCode->asset_id);
        }

        return null;
    }

    /**
     * Is the target still part of the live estate?
     *
     * Archived (soft-deleted) counts as not live: FR-QR-012 excludes archived
     * rows from the scan-scoped panel outright, so a scan that resolved to one
     * has nothing legitimate to show and must not proceed.
     *
     * For assets this reuses {@see AssetStatus::isLive()} rather than restating
     * "not retired and not disposed" — the enum already owns that question, and
     * a second copy would be a second thing to update when the domain widens
     * again (it did, in Phase 2.5).
     */
    private function targetIsLive(Asset|PcUnit $target): bool
    {
        if ($target->trashed()) {
            return false;
        }

        return $target instanceof PcUnit
            ? $target->status !== PcStatus::Retired
            : $target->status->isLive();
    }
}
