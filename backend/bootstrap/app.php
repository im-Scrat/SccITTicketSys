<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsCurrent;
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
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum SPA cookie auth on the API group (single-origin).
        $middleware->statefulApi();

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
