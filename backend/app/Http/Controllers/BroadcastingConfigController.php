<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Hands the SPA the one value it needs to open a Reverb socket: the app KEY
 * (WP-A infrastructure, not a business feature).
 *
 * `install:broadcasting` bakes the key into the bundle as VITE_REVERB_APP_KEY.
 * That does not fit this project: the SPA is built in its own container from
 * frontend/, which never sees backend/.env, and the production web image is
 * built once and must not carry per-deployment values. Serving the key at
 * runtime keeps backend env as the single source of truth and lets production
 * rotate Reverb credentials with a container recreate, no image rebuild.
 *
 * The key is a public identifier — it appears in every socket URL. The app ID
 * and SECRET are not, and are never returned: the secret signs channel
 * authorizations server-side, and anyone holding it could forge them.
 *
 * Host, port and scheme are deliberately absent too: the browser always
 * connects same-origin through nginx at /reverb, so the only correct values
 * are the page's own location.
 */
class BroadcastingConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $key = config('broadcasting.connections.reverb.key');

        // 503, not an empty key: the client should fall back (SDD DD-16)
        // rather than dial a socket that can only be refused.
        if (config('broadcasting.default') !== 'reverb' || ! is_string($key) || $key === '') {
            return response()->json(['message' => 'Real-time updates are not configured.'], 503);
        }

        return response()->json(['key' => $key]);
    }
}
