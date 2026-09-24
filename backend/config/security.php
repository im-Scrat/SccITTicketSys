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

        // Phase 2.6 — authenticated ticket actions, keyed per user rather than
        // per IP so a whole school behind one address does not share a budget.
        'tickets_per_hour' => (int) env('TICKETS_PER_HOUR', 20),
        'ticket_comments_per_hour' => (int) env('TICKET_COMMENTS_PER_HOUR', 60),

        /*
         * WP-2.6b — the QR scan endpoint (FR-QR-013, NFR-SEC-009).
         *
         * Two ceilings, both applied: the endpoint is **public**, so the IP
         * limit is what resists an anonymous enumeration sweep, while the
         * per-account limit stops a signed-in user from doing the same at a
         * higher budget. Per minute rather than per hour because enumeration is
         * a burst and legitimate scanning is not — a technician walking a lab
         * scans a machine every minute or two, not twenty a minute.
         */
        'qr_scan_per_minute' => (int) env('QR_SCAN_PER_MINUTE', 20),
        'qr_scan_per_user_per_minute' => (int) env('QR_SCAN_PER_USER_PER_MINUTE', 30),

        /*
         * WP-2.6b Stage D — proof-of-work submission (FR-MNT-009/010).
         *
         * Per account only: the endpoint is authenticated, so there is no
         * anonymous sweep to resist, and an IP ceiling would ration a whole
         * school behind one address. Generous enough that a technician
         * photographing a job in several passes never meets it, tight enough
         * that a client stuck in a retry loop cannot upload without bound.
         */
        'qr_proof_per_user_per_minute' => (int) env('QR_PROOF_PER_USER_PER_MINUTE', 12),
    ],

    // File uploads (Phase 2.5 asset attachments; SRS NFR-SEC-007/008).
    // The MIME/extension allow-list itself lives in the FormRequest — it is a
    // validation rule, not a tunable — but the size ceiling is deployment
    // policy, so a site can tighten it without a code change.
    'uploads' => [
        'max_kb' => (int) env('UPLOAD_MAX_KB', 10240),
    ],

];
