<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Events;

use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a maintenance visit is opened against a technician
 * (SRS FR-MNT-002; notification matrix T5).
 *
 * Raised for every new record, not only dated ones: a corrective job opened for
 * someone else is work they have been given whether or not a date is attached
 * yet, and the listener — not the event — decides whether that is worth telling
 * them about.
 */
class MaintenanceScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MaintenanceRecord $record,
        public readonly User $actor,
    ) {}
}
