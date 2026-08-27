<?php

namespace App\Providers;

use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Policies\PcUnitPolicy;
use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\Locations\Policies\BuildingPolicy;
use App\Domains\Locations\Policies\FloorPolicy;
use App\Domains\Locations\Policies\RoomPolicy;
use App\Domains\Tickets\Policies\TicketAssignmentPolicy;
use App\Domains\Tickets\Policies\TicketCommentPolicy;
use App\Domains\Tickets\Policies\TicketPolicy;
use App\Models\Asset;
use App\Models\Building;
use App\Models\Floor;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
