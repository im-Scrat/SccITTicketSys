import { api } from '@/services/api'
import { getLease } from '@/services/tabLease'

/**
 * Keeps this tab's lease (services/tabLease.ts) alive while it stays open,
 * and gives the server a heads-up when the tab closes on purpose.
 *
 * **Why a heartbeat at all.** The lease middleware (`EnsureTabLeaseIsValid`)
 * treats a lease unheard-from for too long as abandoned and ends the whole
 * session — the backstop for a crash, a kill, or a lost network, none of
 * which can reach the server any other way. A tab that is simply open and
 * idle (the user reading a long ticket, not clicking anything) would go
 * quiet by the same measure without this; heartbeating regardless of
 * whether the user does anything is what keeps "idle" and "closed" distinct.
 *
 * **Why also on visibilitychange.** Backgrounded tabs have their timers
 * throttled by the browser, sometimes well past the stale window. Firing one
 * heartbeat immediately when the tab becomes visible again covers the gap
 * without needing a shorter (and more trigger-happy) interval.
 *
 * **Why `sendBeacon` for the close.** By the time `pagehide` fires, the page
 * cannot await a normal request — `fetch`/`axios` calls get cancelled mid-
 * flight as the document unloads. `sendBeacon` is built for exactly this: a
 * fire-and-forget POST the browser guarantees it will at least attempt. It
 * cannot carry a custom header, so the lease travels in the query string
 * instead — see `TabSessionController::close()` for why that is still safe
 * without a CSRF token.
 */
const HEARTBEAT_INTERVAL_MS = 60_000

let heartbeatTimer: ReturnType<typeof setInterval> | null = null

function sendHeartbeat(): void {
  if (!getLease()) return
  void api.post('/auth/heartbeat').catch(() => {
    // A failed heartbeat means the lease is already gone (or about to be);
    // the next real API call's 401 is what actually drives the user back to
    // sign-in (services/api.ts's response interceptor). Nothing to do here.
  })
}

function sendCloseBeacon(): void {
  const lease = getLease()
  if (!lease) return
  navigator.sendBeacon(`/api/auth/tab-close?lease=${encodeURIComponent(lease)}`)
}

function handleVisibilityChange(): void {
  if (document.visibilityState === 'visible') {
    sendHeartbeat()
  }
}

/**
 * Starts the heartbeat/close-beacon wiring once for the app's lifetime.
 * Idempotent so it is safe to call from a component that may re-render.
 */
export function initTabSession(): void {
  if (heartbeatTimer !== null) return

  heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS)
  document.addEventListener('visibilitychange', handleVisibilityChange)
  window.addEventListener('pagehide', sendCloseBeacon)
}
