<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Domains\Analytics\Services\ReportsAccess;
use App\Models\User;

/**
 * GET /api/admin/reports/ticket-overview (Phase 2.10) — the shared filter
 * vocabulary, no extra parameters.
 *
 * The overview is ticket data, so it also needs `tickets.view`; narrowing by a
 * location also needs `locations.view` (ReportsAccess's domain gates). Both
 * answer 403 before any validation message.
 */
class TicketOverviewRequest extends ReportFilterRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $access = app(ReportsAccess::class);

        return $access->canViewTickets($user)
            && (! $this->narrowsByLocation() || $access->canViewLocations($user));
    }
}
