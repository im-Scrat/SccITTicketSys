<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\DTOs;

use App\Models\MaintenanceRecord;

/**
 * What one proof-of-work submission actually did (SRS FR-MNT-009/012).
 *
 * Returned instead of a bare record because the caller genuinely needs to know
 * *which* of three things happened — the record was created, attached to, or
 * the submission was a replay of one already bound to this scan — and a
 * `MaintenanceRecord` alone cannot say. The client renders a different sentence
 * for each, and the tests assert on them rather than inferring from row counts.
 */
final readonly class ProofOfWorkResult
{
    /**
     * @param  bool  $created  a record was opened by this submission (FR-MNT-009's
     *                         narrow exception, never its default path)
     * @param  bool  $replayed  the quoted scan was already bound to a record, so
     *                          this submission updated it rather than binding again
     * @param  int  $evidenceAdded  items stored by this submission
     * @param  int  $evidenceSkipped  items already on the record, byte for byte, in
     *                                the same stage — the duplicate-submit case
     */
    public function __construct(
        public MaintenanceRecord $record,
        public bool $created,
        public bool $replayed,
        public int $evidenceAdded,
        public int $evidenceSkipped,
    ) {}
}
