<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Events;

use App\Enums\WorkSupportStatus;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when an administrator answers a work support request — approved with a
 * new schedule, clarification requested, or declined with a reason
 * (SRS FR-WSR-006/007/008, FR-WSR-012; notification matrix T10).
 *
 * The other half of the WP-2.6b carry-forward. One event for all three
 * decisions, because the recipient is the same person for the same reason in
 * each case and the only thing that varies is the wording — which belongs to the
 * notification, not to the event.
 *
 * Deliberately **not** raised for `cancelled` or `closed`: a withdrawal is the
 * technician's own act, and a conclusion is administrative housekeeping on a
 * decision they were already told about.
 */
class WorkSupportRequestDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkSupportRequest $request,
        public readonly WorkSupportStatus $decision,
        public readonly User $actor,
    ) {}
}
