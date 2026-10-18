<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Reporting\ReportArea;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The single answer to "may this user see reports, and which parts?" (Phase
 * 2.10, owner decision D8, SDD DD-89).
 *
 * **Reports are Administrator-only.** Technicians keep the seeded
 * `reports.view` grant (the Client's §8.4 matrix is unchanged), and it opens
 * nothing here, because both halves are required:
 *
 *  - **The role.** `Gate::before` in AppServiceProvider allows any ability whose
 *    *name* equals a permission in the user's effective set, and grants are
 *    editable per user (FR-USER-010). A `can:reports.view` gate would therefore
 *    open for every technician on the spot. The report routes are gated by the
 *    dotless policy abilities on {@see ReportArea}, which never match a
 *    permission name, and those delegate here.
 *  - **The permission.** An Administrator's own set is still honoured: a
 *    per-user *deny* (deny wins, DD-05) withdraws reports from a named
 *    administrator without demoting them.
 *
 * **Domain gates.** A report shows another module's data, so it also needs that
 * module's read permission: ticket figures need `tickets.view`, location
 * breakdowns and the room/building lists need `locations.view`. A block the
 * viewer may not see is left out, never shown empty, and the set of visible
 * domains is part of every report cache key so a payload is never shared across
 * viewers who may see different blocks.
 */
final class ReportsAccess
{
    public const DOMAIN_TICKETS = 'tickets';

    public const DOMAIN_LOCATIONS = 'locations';

    public function canView(User $actor): bool
    {
        return $actor->isAdministrator() && $actor->hasPermissionTo('reports.view');
    }

    /** Export, email and print: the same gate plus `reports.export`. */
    public function canExport(User $actor): bool
    {
        return $this->canView($actor) && $actor->hasPermissionTo('reports.export');
    }

    public function canViewTickets(User $actor): bool
    {
        return $this->canView($actor) && $actor->hasPermissionTo('tickets.view');
    }

    public function canViewLocations(User $actor): bool
    {
        return $this->canView($actor) && $actor->hasPermissionTo('locations.view');
    }

    /**
     * The module data this viewer may see in a report, sorted, for cache keys.
     *
     * @return list<string>
     */
    public function visibleDomains(User $actor): array
    {
        return array_values(array_filter([
            $this->canViewLocations($actor) ? self::DOMAIN_LOCATIONS : null,
            $this->canViewTickets($actor) ? self::DOMAIN_TICKETS : null,
        ]));
    }

    /**
     * For services reached outside an HTTP request (queued exports, the
     * assistant): refuse before reading any cache.
     *
     * @throws AuthorizationException
     */
    public function assertCanView(User $actor): void
    {
        if (! $this->canView($actor)) {
            throw new AuthorizationException('Reports are available to administrators only.');
        }
    }
}
