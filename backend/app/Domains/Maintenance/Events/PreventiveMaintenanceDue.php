<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Events;

use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised by the daily `maintenance:detect-due` sweep for each record that is
 * overdue or falls inside the reminder window (SRS FR-MNT-007; notification
 * matrix T5).
 *
 * ── The sweep runs every day; the notification must not ────────────────────
 *
 * `maintenance:detect-due` re-derives the same overdue set every morning, so a
 * naive listener would tell the same technician about the same machine
 * indefinitely. The listener's idempotency key is therefore scoped to the
 * record **and the day**: at most one reminder per record per day, and the
 * `overdue` flag is part of it so the day a visit tips from *due soon* into
 * *overdue* is announced as the new fact it is.
 *
 * There is deliberately no actor. Nobody did this; a date passed.
 */
class PreventiveMaintenanceDue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MaintenanceRecord $record,
        public readonly bool $overdue,
    ) {}
}
