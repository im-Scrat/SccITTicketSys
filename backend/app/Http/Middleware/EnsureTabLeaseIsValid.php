<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-browser-tab session scoping (client security requirement, 2026-10-01).
 *
 * Sanctum's session cookie is shared by every tab in the browser — there is no
 * way to scope a cookie to one tab, so a new tab sailing in on an existing
 * cookie is normal browser behaviour, not a bug. This middleware adds a
 * second, tab-scoped secret on top of the cookie: a random token the SPA
 * keeps only in `sessionStorage` (never the cookie jar, never localStorage),
 * which the browser clears when a tab closes and never shares with a sibling
 * tab. `AuthService::login()` stamps one into the session on sign-in; the SPA
 * echoes it back as `X-Tab-Lease` on every request (services/api.ts). A tab
 * that never itself logged in — a freshly opened one, riding the same cookie —
 * has nothing to echo, so it is turned back to the sign-in page exactly where
 * the cookie alone would have waved it through.
 *
 * **Backward-compatible by construction, not by exemption.** If the session
 * carries no `tab_lease` at all, this middleware steps aside rather than
 * rejecting. In production no authenticated session can reach that state —
 * `AuthService::login()` stamps one unconditionally on every successful
 * login — so the only sessions that ever lack one are the feature test
 * suite's `actingAs()` fixtures, which resolve the user directly on the auth
 * guard and never touch `session()` (SESSION_DRIVER=array in phpunit.xml, a
 * fresh empty store per request). Real sessions are covered from their very
 * first authenticated request onward; nothing had to special-case tests.
 *
 * **Staleness, for the tab that can never say goodbye.** A closed tab cannot
 * reliably tell the server it left — crash, kill, lost network — so
 * `TabSessionController::close()` (a best-effort `sendBeacon` on `pagehide`)
 * is a courtesy, not the guarantee. The real backstop is here: the SPA
 * heartbeats this lease every so often (`POST /api/auth/heartbeat`), and this
 * middleware refreshes the timestamp on every successful check besides. A
 * lease unheard from for longer than `security.tab_lease.stale_after_seconds`
 * is treated as abandoned — the whole session is invalidated, not just this
 * one request refused, so a browser's crash-recovery replay of the same
 * sessionStorage value cannot revive it.
 */
class EnsureTabLeaseIsValid
{
    private const TOKEN_KEY = 'tab_lease';

    private const LAST_SEEN_KEY = 'tab_lease_last_seen';

    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(self::TOKEN_KEY)) {
            return $next($request);
        }

        $session = $request->session();
        $staleAfter = (int) config('security.tab_lease.stale_after_seconds', 180);
        $lastSeen = (int) $session->get(self::LAST_SEEN_KEY, 0);

        if (now()->timestamp - $lastSeen > $staleAfter) {
            /*
             * flush()+save(), not invalidate(): aborting below throws past
             * StartSession's own post-pipeline save, so invalidate()'s
             * regenerated-ID mutation would otherwise never reach the
             * backing store and this session would outlive the request that
             * just condemned it. Saving explicitly, under the same id,
             * empties it immediately regardless of how this response leaves.
             */
            $this->auth->guard('web')->logout();
            $session->flush();
            $session->save();

            abort(401, 'This tab’s session timed out. Please sign in again.');
        }

        $header = (string) $request->header('X-Tab-Lease', '');
        $expected = (string) $session->get(self::TOKEN_KEY);

        if ($header === '' || ! hash_equals($expected, $header)) {
            abort(401, 'Sign in again in this tab.');
        }

        $session->put(self::LAST_SEEN_KEY, now()->timestamp);

        return $next($request);
    }
}
