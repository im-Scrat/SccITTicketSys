<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-integrity guard for first-party Sanctum SPA auth (SRS FR-AUTH-011).
 *
 * Laravel's built-in AuthenticateSession assumes the *default* guard is the
 * session guard, but under `auth:sanctum` the default becomes the `sanctum`
 * RequestGuard (which has no session methods). This variant operates explicitly
 * on the `web` (session) guard.
 *
 * It stamps a fingerprint of the user's current password hash into the session
 * (under a private key, to avoid colliding with SessionGuard's own
 * `password_hash_web`) and compares it on each request. When a password
 * changes, only the session that made the change is re-stamped, so every OTHER
 * active session is signed out on its next request — how "changing your
 * password signs out your other sessions" works with a Redis session store that
 * can't be enumerated per user.
 */
class AuthenticateSession
{
    private const KEY = 'sccit_pw_fingerprint';

    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('web');
        $user = $guard->user();

        if (! $request->hasSession() || $user === null) {
            return $next($request);
        }

        $fingerprint = $this->fingerprint($user->getAuthPassword());

        if (! $request->session()->has(self::KEY)) {
            $request->session()->put(self::KEY, $fingerprint);
        }

        if (! hash_equals((string) $request->session()->get(self::KEY), $fingerprint)) {
            $request->session()->flush();

            abort(401, 'Your session has ended. Please sign in again.');
        }

        $response = $next($request);

        // Re-stamp so the session that just changed the password stays valid.
        $current = $guard->user();
        if ($current !== null) {
            $request->session()->put(self::KEY, $this->fingerprint($current->getAuthPassword()));
        }

        return $response;
    }

    private function fingerprint(?string $authPassword): string
    {
        return hash('sha256', (string) $authPassword);
    }
}
