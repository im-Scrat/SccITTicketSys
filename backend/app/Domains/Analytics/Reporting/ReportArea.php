<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Reporting;

/**
 * The subject of the report area's policy (Phase 2.10, SDD DD-89).
 *
 * Reports have no model to attach a policy to, and a `can:reports.view`
 * middleware would be opened by a per-user grant (the `Gate::before` trap
 * ReportsAccess documents). A class name gives `Gate::policy()` something to
 * key on, so the whole `/api/admin/reports` group can be closed by
 * `can:viewAny,ReportArea` — a dotless ability no permission can match.
 * It is never instantiated.
 */
final class ReportArea {}
