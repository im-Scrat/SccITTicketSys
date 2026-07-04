<?php

namespace App\Providers;

use App\Domains\Identity\Policies\UserPolicy;
use App\Domains\Identity\Services\PermissionResolver;
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
