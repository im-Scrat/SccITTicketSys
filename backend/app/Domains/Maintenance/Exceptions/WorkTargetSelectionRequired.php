<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Exceptions;

use App\Models\MaintenanceRecord;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The scanned machine has **more than one** maintenance record this technician
 * may work, so the server refuses to choose (SRS FR-MNT-009; Client decision,
 * 2026-08-29: *"do not guess; require explicit technician selection"*).
 *
 * Carries the candidates so the client can render a chooser without a second
 * round trip — and so the refusal is actionable rather than merely correct. The
 * rows are already scoped by the same predicate that authorized the panel, so
 * naming them discloses nothing the caller could not already see.
 */
class WorkTargetSelectionRequired extends RuntimeException
{
    /**
     * @param  Collection<int, MaintenanceRecord>  $candidates
     */
    public function __construct(public readonly Collection $candidates)
    {
        parent::__construct('More than one maintenance record is open for this unit.');
    }
}
