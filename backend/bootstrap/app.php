<?php

use App\Domains\Administration\Console\SendDailyDigest;
use App\Domains\Maintenance\Console\DetectDuePreventiveMaintenance;
use App\Domains\Tickets\Console\CloseStaleResolvedTickets;
use App\Domains\Tickets\Console\DetectSlaBreaches;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * Private-channel authorization — `GET|POST /broadcasting/auth` (WP-A).
     *
     * Registered here rather than through `withRouting(channels: …)`, which is
     * what `install:broadcasting` writes. That shorthand puts the endpoint in
     * the `web` group: session-guard auth with none of this application's
     * account rules. A socket subscription is a read of whatever the channel
     * carries, so it gets exactly the gate an API read gets — the same stack as
     * the feature routes in routes/api.php:
     *
     *   api               Sanctum's stateful SPA handling (cookie session +
     *                     XSRF), the same first-party origin check as /api/*
     *   auth:sanctum      no principal → 401, before any channel callback runs
     *   active            a suspended account cannot subscribe (SDD DD-19)
     *   AuthenticateSession
     *                     a session invalidated by a password change on another
     *                     device cannot keep authorizing channels (FR-AUTH-011)
     *   password.current  an account under forced password reset has no feature
     *                     access, so it has nothing to subscribe to
     *
     * The path stays `/broadcasting/auth` — no `api` prefix — so nginx routes it
     * to Laravel explicitly in both stacks rather than it riding on `/api/`.
     */
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['api', 'auth:sanctum', 'active', AuthenticateSession::class, 'password.current'],
    ])
    /*
     * Domain commands are registered explicitly.
     *
     * Laravel auto-discovers only `app/Console/Commands`, and this project keeps
     * behaviour with the domain it belongs to (SDD DD-02) rather than in a
     * framework-shaped folder. Listing them here is the small price of that
     * choice — and it fails loudly if one is ever moved, which auto-discovery
     * would not.
     */
    ->withCommands([
        CloseStaleResolvedTickets::class,
        SendDailyDigest::class,
        DetectDuePreventiveMaintenance::class,
        DetectSlaBreaches::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum SPA cookie auth on the API group (single-origin).
        $middleware->statefulApi();

        /*
         * Baseline security headers on everything Laravel serves — JSON and
         * attachment streams alike (SDD DD-46). Appended to both groups rather
         * than only to `api`, so a response can never leave the application
         * without nosniff simply because a future route was registered
         * elsewhere. The SPA document's own CSP comes from nginx, which is what
         * actually serves it.
         */
        $middleware->append(SecurityHeaders::class);

        // Account-status enforcement — the single source of truth (SDD DD-19).
        // `password.current` gates feature routes behind the force-password-reset
        // flag (SRS FR-USER admin action); `/user`, `/password`, `/logout` stay open.
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'password.current' => EnsurePasswordIsCurrent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients always receive JSON (they send Accept: application/json),
        // so AuthenticationException→401 and AuthorizationException→403 are
        // rendered as JSON by the framework. AccountNotActiveException renders
        // its own status-coded 403 (see the exception's render()).
    })->create();
