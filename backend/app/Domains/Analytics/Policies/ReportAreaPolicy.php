<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Policies;

use App\Domains\Analytics\Reporting\ReportArea;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Models\User;

/**
 * Authorization for the report area (Phase 2.10, owner decision D8, SDD DD-89).
 *
 * Administrator-only — see {@see ReportsAccess} for why the role is required as
 * well as the permission. Every ability delegates there, so a route gate, a
 * FormRequest and a queued job can never disagree. Registered against the
 * {@see ReportArea} marker and used as `can:viewAny,ReportArea` on the whole
 * `/api/admin/reports` group (a test asserts every route there carries it) and
 * as `can:export,ReportArea` on the export, email and print routes.
 */
class ReportAreaPolicy
{
    public function __construct(private readonly ReportsAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canView($actor);
    }

    public function export(User $actor): bool
    {
        return $this->access->canExport($actor);
    }
}
