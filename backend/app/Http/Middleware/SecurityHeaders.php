<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Attachments\AttachmentSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for everything Laravel serves (SDD DD-46).
 *
 * ── Scope, and why it is narrower than it looks ────────────────────────────
 *
 * Laravel does **not** serve the SPA. Nginx serves the built HTML in production
 * and proxies to the Vite dev server in development, so the page-level Content
 * Security Policy — the one that governs the document the user is actually
 * looking at — lives in `docker/nginx/*.conf` and cannot be set from here. What
 * Laravel serves is JSON and file streams.
 *
 * That makes this middleware's job specific rather than decorative:
 *
 *  - **`X-Content-Type-Options: nosniff`** on every response. The attachment
 *    routes need it most (a browser must not sniff its way from the declared
 *    type to a dangerous one), but a JSON endpoint that gets sniffed as HTML is
 *    the same class of bug, so it is applied uniformly rather than per-route.
 *  - **`Referrer-Policy`** so a uuid in a path never leaks to a third party.
 *  - **`X-Frame-Options: DENY`** — no Laravel response is ever meant to be
 *    framed. Clickjacking cover for the API surface; the SPA gets the same from
 *    nginx via `frame-ancestors`.
 *  - **A response-level CSP** of `default-src 'none'; frame-ancestors 'none';
 *    sandbox`. An API response has no legitimate need to load anything at all,
 *    so the strictest possible policy is also the correct one. This is the
 *    backstop that made the attachment vulnerability survivable in depth: even
 *    a response that somehow carried `text/html` cannot execute script under
 *    `sandbox` with no permitted sources.
 *
 * {@see AttachmentSecurity::stream()} sets the same CSP on the file stream
 * itself, so an attachment is covered whether or not this middleware is in the
 * stack — the two are deliberately redundant, because a header that only
 * *usually* applies is not a security control.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Never widen an already-set policy: AttachmentSecurity::stream() has
        // its own, and a later `set()` here would silently replace it.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'; sandbox",
            );
        }

        return $response;
    }
}
