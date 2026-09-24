<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Events;

use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a visit's `scheduled_for` moves (SRS FR-MNT-003, FR-WSR-006;
 * notification matrix T6).
 *
 * Both the administrator's direct edit and the work-support approval path raise
 * this, because the technician's question is the same in both cases — *when is
 * it now?* — and a second event for the second path would need the same listener
 * anyway.
 *
 * `$from` is the previous ISO-8601 date or null; a visit given a date for the
 * first time is a reschedule from nothing, which is still news to whoever has
 * to be there.
 */
class MaintenanceRescheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MaintenanceRecord $record,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly User $actor,
        public readonly ?string $reason = null,
    ) {}
}
