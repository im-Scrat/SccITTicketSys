import { useEffect, useRef } from 'react'
import { useIsPreview } from '@/contexts/PreviewModeContext'
import { getEcho } from '@/services/echo'

/**
 * Event name (as Echo's `listen` takes it, e.g. `.dashboard.changed`) → handler.
 * Handlers are read through a ref on every event, so an inline object is fine;
 * the *set of event names* is fixed for the life of a subscription.
 */
export type ChannelListeners = Record<string, (payload: never) => void>

interface PrivateChannelOptions {
  /** Told `true` once the server authorized the subscription, `false` when it ends or fails. */
  onLiveChange?: (live: boolean) => void
}

/**
 * Subscribe to one private Reverb channel for as long as the caller is mounted
 * (Phase 2.9 — extracted from the floor plan's WP-E hook so every realtime
 * surface subscribes, degrades and tears down the same way).
 *
 * - **No name, no socket.** `undefined` (no user yet, not an administrator)
 *   never touches Echo, so screens that do not need realtime pay nothing.
 * - **Degrades, never breaks.** If the socket cannot connect or the server
 *   refuses the channel, the caller keeps working from ordinary fetches; the
 *   only effect is `onLiveChange(false)`.
 * - **Leaves on unmount or rename**, so a channel authorized for one user is
 *   never left listening after the screen — or the principal — changes.
 *
 * Channel *authorization* stays on the server (routes/channels.php); this hook
 * only asks.
 */
export function usePrivateChannel(
  name: string | undefined,
  listeners: ChannelListeners,
  options: PrivateChannelOptions = {},
): void {
  const listenersRef = useRef(listeners)
  listenersRef.current = listeners
  const optionsRef = useRef(options)
  optionsRef.current = options

  const events = Object.keys(listeners).sort().join('|')
  // A page previewed inside the import review opens no socket of its own.
  const preview = useIsPreview()

  useEffect(() => {
    if (!name || preview) return

    let cancelled = false
    let subscribed: string | null = null

    getEcho()
      .then((echo) => {
        if (cancelled) return
        subscribed = name
        const channel = echo.private(name)

        for (const event of events.split('|').filter(Boolean)) {
          channel.listen(event, (payload: never) => listenersRef.current[event]?.(payload))
        }

        // Optional in the channel API surface the tests stub, so guarded.
        channel.subscribed?.(() => optionsRef.current.onLiveChange?.(true))
        channel.error?.(() => optionsRef.current.onLiveChange?.(false))
      })
      .catch(() => {
        optionsRef.current.onLiveChange?.(false)
      })

    return () => {
      cancelled = true
      optionsRef.current.onLiveChange?.(false)
      if (subscribed) void getEcho().then((echo) => echo.leave(subscribed!))
    }
  }, [name, events, preview])
}
