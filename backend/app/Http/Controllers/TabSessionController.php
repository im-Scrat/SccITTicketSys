<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Per-tab session lifecycle endpoints (see `EnsureTabLeaseIsValid`).
 */
class TabSessionController extends Controller
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * Keep an idle-but-open tab's lease alive. Gated behind the same
     * `tab.lease` middleware as every other feature route, so simply reaching
     * this action already refreshed the lease's last-seen timestamp — the
     * body has nothing left to do. It exists so the SPA has something
     * harmless to call every so often when the user isn't otherwise
     * generating traffic (`frontend/src/services/tabSession.ts`).
     */
    public function heartbeat(): Response
    {
        return response()->noContent();
    }

    /**
     * Best-effort "I'm closing" beacon, sent via `navigator.sendBeacon` from
     * the tab's `pagehide` handler. `sendBeacon` cannot attach a custom
     * header, so this route carries its own authorization in the query
     * string and is exempt from CSRF verification (see `bootstrap/app.php`)
     * rather than requiring `auth:sanctum` — safely, because the only thing
     * that authorizes the action is knowledge of `lease`, a secret that lived
     * solely in this tab's `sessionStorage` and was never sent to any other
     * origin. A forged cross-site beacon cannot know it, and
     * `SESSION_SAME_SITE=lax` already keeps the session cookie off a
     * cross-site POST regardless — the token is defense in depth, not the
     * only thing standing in the way.
     *
     * Invalidates the whole session, not just the lease: the ordinary case is
     * one tab, closing on purpose, and the point of this feature is that
     * closing it ends the session outright rather than leaving it to linger
     * for up to `SESSION_LIFETIME`.
     */
    public function close(Request $request): Response
    {
        $lease = (string) $request->query('lease', '');

        if ($lease !== '' && $request->hasSession()
            && hash_equals((string) $request->session()->get('tab_lease', ''), $lease)
        ) {
            // flush()+save() rather than invalidate(): this response carries
            // nothing back that would need the regenerated id, and saving
            // explicitly removes any dependency on exactly when the session
            // middleware's own post-pipeline save would otherwise run.
            $this->auth->guard('web')->logout();
            $request->session()->flush();
            $request->session()->save();
        }

        return response()->noContent();
    }
}
