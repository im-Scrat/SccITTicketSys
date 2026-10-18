<?php

namespace App\Providers;

use App\Domains\Analytics\Policies\ReportAreaPolicy;
use App\Domains\Analytics\Reporting\ReportArea;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Policies\PcUnitPolicy;
use App\Domains\FloorPlan\Policies\FloorPlanPositionPolicy;
use App\Domains\FloorPlan\Policies\RoomLayoutPolicy;
use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\KnowledgeBase\Policies\AiPredictionPolicy;
use App\Domains\KnowledgeBase\Policies\AiSystemSettingPolicy;
use App\Domains\KnowledgeBase\Policies\KnowledgeArticlePolicy;
use App\Domains\Locations\Policies\BuildingPolicy;
use App\Domains\Locations\Policies\FloorPolicy;
use App\Domains\Locations\Policies\RoomPolicy;
use App\Domains\Maintenance\Policies\MaintenanceRecordPolicy;
use App\Domains\Tickets\Policies\TicketAssignmentPolicy;
use App\Domains\Tickets\Policies\TicketCommentPolicy;
use App\Domains\Tickets\Policies\TicketPolicy;
use App\Domains\WorkSupport\Policies\WorkSupportRequestPolicy;
use App\Models\AiKnowledgeArticle;
use App\Models\AiPrediction;
use App\Models\AiSystemSetting;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\FloorPlanPosition;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\RoomLayout;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PhpOffice\PhpWord\Settings as WordSettings;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthorization();
        $this->registerRateLimiters();
        $this->registerPasswordResetUrl();
        $this->registerDocumentEscaping();
    }

    /**
     * Phase 2.10 (SDD DD-85/DD-92) — escaping that the document libraries
     * leave off by default, switched on once for the whole application.
     *
     *  - **PhpWord** writes text runs as raw XML unless output escaping is on,
     *    so a room named `<w:fldSimple …/>` would become a live Word field.
     *    ReportDocxRenderer refuses to run while it is off.
     *  - **Mail Markdown** escapes the values interpolated into a message, so a
     *    room name written as `[click](http://…)` renders as text, not a link.
     *    Checked against the existing notification mails, which interpolate
     *    plain values only.
     */
    private function registerDocumentEscaping(): void
    {
        WordSettings::setOutputEscapingEnabled(true);
        Markdown::withSecuredEncoding();
    }

    /**
     * Deny-by-default RBAC (SDD DD-05): a permission-slug ability is allowed when
     * it is in the user's effective set; otherwise the check falls through to
     * policies (return null, never false, so policy abilities still resolve).
     */
    private function registerAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            return app(PermissionResolver::class)->has($user, $ability) ? true : null;
        });

        Gate::policy(User::class, UserPolicy::class);

        // Locations (Phase 2.4): per-record rules on top of the `locations.*`
        // route gate — chiefly the in-use archive guard (FR-LOC-004).
        Gate::policy(Building::class, BuildingPolicy::class);
        Gate::policy(Floor::class, FloorPolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);

        // Assets (Phase 2.5): per-record rules on top of the `assets.*` route
        // gate. Two things live here that a route gate cannot express — the
        // `assets.dispose` requirement on terminal status transitions (DD-33),
        // and the narrow `selectAsset`/`selectPcUnit` lookup abilities that a
        // non-admin workflow's own permission grants (DD-38).
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(PcUnit::class, PcUnitPolicy::class);

        // Tickets (Phase 2.6): unlike Locations and Assets, this module is not
        // closed by withholding a permission — all three roles legitimately hold
        // `tickets.view`. Instead every read ability delegates to
        // `TicketVisibility`, the same service the list queries use, so a ticket
        // absent from a user's list is also unreachable by uuid (DD-40).
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(TicketComment::class, TicketCommentPolicy::class);
        Gate::policy(TechnicianAssignment::class, TicketAssignmentPolicy::class);

        // Maintenance (Phase 2.7): the same shape as Tickets, for the same
        // reason — `maintenance.*` is seeded to Administrators and
        // Technicians alike, so the permission cannot say whose work a
        // record is. `MaintenanceVisibility` does, for lists and for single
        // records, from one predicate (DD-55).
        Gate::policy(MaintenanceRecord::class, MaintenanceRecordPolicy::class);

        // Work support requests (WP-2.6b Stage E): the third module whose
        // permission does not separate the roles. No `wsr.*` permission was
        // invented — that would change the Client's §8.4 matrix — so
        // `maintenance.view` is the floor and `WorkSupportVisibility` decides
        // whose requests, for lists and for single records alike (DD-54, OD-4).
        Gate::policy(WorkSupportRequest::class, WorkSupportRequestPolicy::class);

        // Interactive Floor Plan (Phase 2.8): Administrator-only, and closed by
        // the *policy*, not by a permission string. `Gate::before` above allows
        // any ability whose name equals a permission in the user's set, and
        // per-user grants (FR-USER-010) can put `floorplan.*` in a
        // Technician's — so a `can:floorplan.manage` gate would open for them.
        // The policy abilities (`viewAny`/`view`/`manage`) never match a
        // permission name, so `Gate::before` returns null and the policy
        // decides, requiring the Administrator role as well as the permission.
        Gate::policy(RoomLayout::class, RoomLayoutPolicy::class);
        Gate::policy(FloorPlanPosition::class, FloorPlanPositionPolicy::class);

        // Predictive-maintenance findings (WP-M): Administrator-only, closed by the
        // policy and never by a bare `predictions.*` permission string — see
        // PcPredictionAccess for the `Gate::before` trap that rules out.
        Gate::policy(AiPrediction::class, AiPredictionPolicy::class);

        // AI administration (WP-O): the same Administrator-only, policy-closed
        // shape — see AiAdministrationAccess.
        Gate::policy(AiSystemSetting::class, AiSystemSettingPolicy::class);

        // Knowledge articles (WP-Q): `knowledge.view` is seeded to all three
        // roles, so the permission cannot say which articles. KnowledgeVisibility
        // does — for the list and for a single article from one predicate, so an
        // article absent from a user's list is equally unreachable by uuid.
        Gate::policy(AiKnowledgeArticle::class, KnowledgeArticlePolicy::class);

        // Reports (Phase 2.10, owner decision D8): Administrator-only, closed by
        // the policy on a marker class — reports have no model — and never by a
        // bare `reports.*` string, which the seeded technician grant of
        // `reports.view` would open through `Gate::before`. See ReportsAccess.
        Gate::policy(ReportArea::class, ReportAreaPolicy::class);
    }

    /**
     * Per-IP throttles for sensitive public endpoints (NFR-SEC-009). Login has
     * its own email+IP lockout in AuthService (FR-AUTH-006).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('registrations', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.registration_per_hour', 5),
        )->by($request->ip() ?? 'unknown'));

        RateLimiter::for('password', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.password_per_hour', 6),
        )->by($request->ip() ?? 'unknown'));

        /*
         * Phase 2.6. Keyed by **user**, not IP: these are authenticated actions,
         * and a whole school behind one NAT address would otherwise share a
         * single budget. The ceilings are set to stop runaway automation, not to
         * ration ordinary work — a teacher reporting ten faults in an hour is
         * having a bad day, not abusing the system.
         *
         * Votes need no limiter: they are unique-constrained and idempotent, so
         * repeating one changes nothing.
         */
        RateLimiter::for('tickets', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.tickets_per_hour', 20),
        )->by((string) ($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        RateLimiter::for('ticket-comments', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.ticket_comments_per_hour', 60),
        )->by((string) ($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        /*
         * Phase 2.9 — report-a-problem form (SDD DD-78/80). Same shape and
         * reasoning as `ticket-comments`: per account, ceilings set against
         * runaway automation rather than ordinary use.
         *
         * `ticket-attachments`: the form uploads each photo separately after
         * creating the ticket, so a burst of uploads is now normal — but a
         * client in a retry loop, or a script, must not upload without bound.
         * `lookup-equipment`: one call per room a reporter picks; walking every
         * room is the only way to assemble a located inventory from it.
         */
        RateLimiter::for('ticket-attachments', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.ticket_attachments_per_hour', 60),
        )->by((string) ($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        RateLimiter::for('lookup-equipment', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.rate_limits.lookup_equipment_per_minute', 120),
        )->by((string) ($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        /*
         * Phase 2.9. The teacher AI panel used to borrow `tickets`, but a named
         * limiter's bucket is (name + key): once `tickets` was finally applied
         * to ticket *creation*, every panel view would have spent the same
         * user's creation budget, and vice versa. Reading a stored analysis
         * makes no provider call, so its ceiling is higher and its own.
         */
        RateLimiter::for('ticket-ai-analysis', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.ticket_ai_analysis_per_hour', 120),
        )->by((string) ($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        /*
         * WP-2.6b — the QR scan endpoint (FR-QR-013).
         *
         * The only limiter in this application that returns **two** limits, and
         * the requirement is explicit about why: "the scan endpoint shall be
         * rate-limited per client **and** per account". Both apply to every
         * request and the stricter one wins.
         *
         * Keyed per IP *and* per user rather than the usual "user, falling back
         * to IP", because this route is reachable with no session at all: the
         * IP ceiling is the one that resists an anonymous enumeration sweep, and
         * signing in must not lift it.
         *
         * @return list<Limit>
         */
        RateLimiter::for('qr-scan', fn (Request $request): array => [
            Limit::perMinute(
                (int) config('security.rate_limits.qr_scan_per_minute', 20),
            )->by('qr-scan:ip:'.($request->ip() ?? 'unknown')),

            Limit::perMinute(
                (int) config('security.rate_limits.qr_scan_per_user_per_minute', 30),
            )->by('qr-scan:user:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')),
        ]);

        /*
         * WP-2.6b Stage D — the proof-of-work submission (FR-MNT-009/010).
         *
         * One limit, keyed per account, because unlike `qr-scan` this endpoint
         * is behind authentication: there is no anonymous caller to ration, and
         * an IP ceiling would punish a school on a single NAT address. It exists
         * to bound file uploads under a retrying client, not to ration work.
         */
        // WP-Q — the assistant: every message is a paid provider call. Per
        // account (a school shares one IP), generous for a person working through
        // a problem and hopeless for a script.
        RateLimiter::for('ai-assistant', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.rate_limits.ai_assistant_per_minute', 10),
        )->by('ai-assistant:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        // WP-O — the administrator's "test connection" makes two paid provider
        // calls; per account, and low, because nobody needs to run it often.
        RateLimiter::for('ai-test', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.ai_test_per_hour', 10),
        )->by('ai-test:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        RateLimiter::for('qr-proof', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.rate_limits.qr_proof_per_user_per_minute', 12),
        )->by('qr-proof:user:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        /*
         * Phase 2.10 — the report aggregates. A cache miss runs several
         * aggregate statements; this is a ceiling for a runaway filter loop,
         * far above what a person clicking through filters produces.
         */
        RateLimiter::for('reports', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.rate_limits.reports_per_minute', 120),
        )->by('reports:user:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        /*
         * Phase 2.10 R3 — documents and email (SDD DD-91/DD-92), per account.
         * Exports and printing each cost a render; email is held tighter,
         * because each request sends mail outside the system. The daily
         * recipient cap is enforced separately, in ReportEmailer.
         */
        RateLimiter::for('report-exports', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.report_exports_per_hour', 60),
        )->by('report-exports:user:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        // Phase 2.10 R5 — AI report interpretations, on top of the shared AI governor.
        RateLimiter::for('report-narratives', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.report_narratives_per_hour', 12),
        )->by('report-narratives:user:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));

        RateLimiter::for('report-emails', fn (Request $request): Limit => Limit::perHour(
            (int) config('security.rate_limits.report_emails_per_hour', 10),
        )->by('report-emails:user:'.($request->user()?->getKey() ?? $request->ip() ?? 'unknown')));
    }

    /**
     * Point the password-reset link at the SPA route (SDD §10.2), single-origin.
     */
    private function registerPasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

            return $base.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
