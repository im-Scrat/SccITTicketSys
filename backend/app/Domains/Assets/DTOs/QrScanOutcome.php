<?php

declare(strict_types=1);

namespace App\Domains\Assets\DTOs;

use App\Domains\Assets\Services\QrScanResolver;
use App\Enums\ScanResult;
use App\Models\Asset;
use App\Models\PcUnit;
use App\Models\QrCode;

/**
 * What a scanned code turned out to be (SRS FR-QR-005/006).
 *
 * Three values that always travel together — the code row, the target it points
 * at, and the deterministic classification — bundled so the resolver, the audit
 * writer and the controller cannot each carry a different subset and disagree
 * about what was scanned.
 *
 * **The classification is not the answer to the caller.** It is what goes into
 * `qr_scan_logs`, always and precisely; what the HTTP response discloses is a
 * separate, deliberately coarser decision made by the controller
 * ([FR-QR-013](#), SDD §35.4). Collapsing the two is what turns a scan log into
 * an enumeration oracle, so they are kept apart by construction: this object has
 * no method that renders itself for a response.
 *
 * @see QrScanResolver
 */
final readonly class QrScanOutcome
{
    public function __construct(
        public ScanResult $result,
        public ?QrCode $qrCode = null,
        public Asset|PcUnit|null $target = null,
    ) {}

    /** An active code resolving to a live target — the only outcome that proceeds. */
    public function isSuccess(): bool
    {
        return $this->result === ScanResult::Success;
    }

    /** The scanned target, when it is a PC unit. Assets have no panel in WP-2.6b. */
    public function pcUnit(): ?PcUnit
    {
        return $this->target instanceof PcUnit ? $this->target : null;
    }
}
