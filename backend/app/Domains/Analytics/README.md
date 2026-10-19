# Analytics domain

Dashboards, reports and the figures behind them.

- **Role dashboards** (Phase 2.4, 2.9): `DashboardService` assembles each role's
  widgets server-side from the caller's permissions (SDD DD-30), cached 30 s
  under the version `AdminDashboardSignal` bumps.
- **Reports** (Phase 2.10): Administrator-only, through `ReportsAccess` and the
  `ReportArea` policy on the `/api/admin/reports` group (SDD DD-89).
  - Time: `App\Support\SchoolClock`, `DTOs/ReportPeriod`, `DTOs/ReportBucket`
    (school-time, half-open periods; SDD DD-86).
  - Filters: `DTOs/ReportFilters`, `Http/Requests/ReportFilterRequest`.
  - Ticket overview: `Services/TicketOverview` (composition, cache),
    `TicketOverviewQuery` (SQL), `TicketOverviewSummary` (rule-based
    sentences), `TicketDrilldown` (links), `TicketLifecycleMetrics`,
    `ReportMetrics` (numbers contract and definitions) — one population via
    `TicketDirectoryQuery::reportScope()` (SDD DD-88).
  - Options for the filter toolbar: `Services/ReportOptions`.

Namespaced `App\Domains\Analytics`, autoloaded by the default PSR-4 mapping.
Models are flat in `app/Models`, enums flat in `app/Enums`.
