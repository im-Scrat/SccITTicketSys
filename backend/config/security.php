<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security / Authentication Tunables
|--------------------------------------------------------------------------
|
| Centralized, environment-overridable settings for the authentication layer
| (SRS FR-AUTH-003/006/009, NFR-SEC-009). Keeping these here avoids scattering
| magic numbers across services, requests, and middleware.
|
*/

return [

    // Account lockout / failed-login protection (RateLimiter, keyed by email+IP).
    'login' => [
        'max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('AUTH_LOGIN_DECAY_MINUTES', 15),
    ],

    // Password policy enforced by App\Domains\Identity\Rules\PasswordPolicy.
    'password' => [
        'min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 10),
        'min_classes' => (int) env('AUTH_PASSWORD_MIN_CLASSES', 3),
    ],

    // Idle-session guidance (the session driver enforces SESSION_LIFETIME).
    'session' => [
        'idle_hours' => (int) env('AUTH_SESSION_IDLE_HOURS', 8),
    ],

    // Per-IP throttles for sensitive public endpoints (throttle:<name>).
    'rate_limits' => [
        'registration_per_hour' => (int) env('AUTH_REGISTRATION_PER_HOUR', 5),
        'password_per_hour' => (int) env('AUTH_PASSWORD_PER_HOUR', 6),
    ],

];
