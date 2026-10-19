import type Echo from 'laravel-echo'
import { api } from '@/services/api'
import { queryClient } from '@/services/queryClient'

/**
 * Real-time client — one lazily created Laravel Echo instance over Reverb
 * (WP-A infrastructure).
 *
 * ── Same origin, always ───────────────────────────────────────────────────
 * The socket dials the page's own host and port at /reverb, which nginx
 * proxies to the internal Reverb service (docker/nginx/{default,prod}.conf).
 * Reverb has no public port, so there is no host, port or scheme to
 * configure — the page's location is the only correct answer, in dev (:8080)
 * and production (:8081) alike, and CSP `connect-src 'self'` covers it.
 *
 * ── The key arrives at runtime ────────────────────────────────────────────
 * `install:broadcasting` would bake VITE_REVERB_APP_KEY into the bundle. This
 * SPA is built in its own container from frontend/, which never sees
 * backend/.env, and the production web image must not carry per-deployment
 * values. So the key — a public identifier — comes from
 * GET /api/broadcasting/config, after sign-in, when a socket is first needed.
 * Nothing Reverb-related is in the build, and the secret never leaves Laravel.
 *
 * ── Authorization rides the app's own HTTP client ─────────────────────────
 * Private-channel auth goes through `api` (baseURL cleared, since the route
 * is /broadcasting/auth, not /api/…), so it carries the Sanctum session and
 * X-XSRF-TOKEN and inherits the 419 retry. pusher-js's built-in authorizer
 * would send neither.
 *
 * ── Loaded only when used ─────────────────────────────────────────────────
 * laravel-echo and pusher-js are dynamically imported, so the public site and
 * every screen that never subscribes pay nothing for them.
 */

type ReverbEcho = Echo<'reverb'>

/** The nginx location that proxies to Reverb. */
const REVERB_PATH = '/reverb'

let instance: ReverbEcho | null = null
let pending: Promise<ReverbEcho> | null = null
/** Bumped on every teardown, so a connect that loses the race is discarded. */
let generation = 0
/** Whose authorization the open socket's subscriptions were granted under. */
let principalId: string | null = null

/** The shared Echo instance, connecting on first use. */
export function getEcho(): Promise<ReverbEcho> {
  if (instance) return Promise.resolve(instance)
  pending ??= connect()
  return pending
}

/**
 * Close the socket and forget every subscription on it.
 *
 * Pusher-protocol channels are authorized once, at subscribe time. A socket
 * that outlived its sign-in would keep delivering the previous user's private
 * channels to whoever signs in next in the same tab — so teardown is tied to
 * the principal below, not left to individual screens.
 */
export function disconnectEcho(): void {
  generation += 1
  instance?.disconnect()
  instance = null
  pending = null
  principalId = null
}

async function connect(): Promise<ReverbEcho> {
  const startedAt = generation

  try {
    const [{ default: EchoClient }, { default: Pusher }, { data }] = await Promise.all([
      import('laravel-echo'),
      import('pusher-js'),
      api.get<{ key: string }>('/broadcasting/config'),
    ])

    const echo = new EchoClient<'reverb'>({
      broadcaster: 'reverb',
      key: data.key,
      Pusher,
      wsHost: window.location.hostname,
      wsPort: Number(window.location.port) || 80,
      wssPort: Number(window.location.port) || 443,
      wsPath: REVERB_PATH,
      forceTLS: window.location.protocol === 'https:',
      enabledTransports: ['ws', 'wss'],
      // Echo would otherwise patch any global axios/jQuery/Turbo it finds.
      withoutInterceptors: true,
      channelAuthorization: {
        endpoint: '/broadcasting/auth',
        transport: 'ajax',
        customHandler: ({ socketId, channelName }, callback) => {
          api
            .post<{ auth: string; channel_data?: string }>(
              '/broadcasting/auth',
              { socket_id: socketId, channel_name: channelName },
              { baseURL: '' },
            )
            .then(({ data: auth }) => callback(null, auth))
            .catch((error: unknown) =>
              callback(
                error instanceof Error ? error : new Error('Channel authorization failed'),
                null,
              ),
            )
        },
      },
    })

    // Signed out (or switched user) while the config request was in flight.
    if (startedAt !== generation) {
      echo.disconnect()
      throw new Error('Real-time connection superseded by a sign-out.')
    }

    instance = echo
    principalId = currentPrincipalId()
    return echo
  } finally {
    if (startedAt === generation) pending = null
  }
}

/** Mirrors AUTH_USER_KEY (features/auth) — services/api.ts uses the same literal. */
function isAuthUserKey(key: readonly unknown[]): boolean {
  return key[0] === 'auth' && key[1] === 'user'
}

function currentPrincipalId(): string | null {
  const user = queryClient.getQueryData<{ id?: string } | null>(['auth', 'user'])
  return user?.id ?? null
}

/*
 * Both sign-out paths — useLogout and the 401 interceptor in services/api.ts —
 * set the principal to null, so watching that one query covers them all
 * without either having to know this module exists.
 */
queryClient.getQueryCache().subscribe((event) => {
  if (!instance && !pending) return
  if (!isAuthUserKey(event.query.queryKey)) return
  if (event.type !== 'updated' && event.type !== 'removed') return

  const user = event.query.state.data as { id?: string } | null | undefined
  const signedOut = event.type === 'removed' || !user
  const switched = principalId !== null && user?.id !== principalId

  if (signedOut || switched) disconnectEcho()
})
