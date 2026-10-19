import { useEffect, useRef, useState } from 'react'
import { UNREAD_POLL_INTERVAL_MS } from '../hooks/queries'

interface UnreadAnnouncerProps {
  unreadCount: number | undefined
  /** Overridable so the tests do not have to wait a real minute. */
  minIntervalMs?: number
}

/**
 * Announces new notifications to a screen reader — and almost nothing else
 * (NFR-ACC-004, WCAG 4.1.3).
 *
 * Three restraints, each of which exists because the obvious implementation is
 * hostile to the people this is for:
 *
 *  1. **Polite, never assertive.** `assertive` interrupts whatever the user is
 *     currently having read to them. A notification arriving is not worth
 *     cutting off the sentence someone is in the middle of.
 *  2. **Increases only.** The count is polled, so it is re-read every minute
 *     whether it changed or not, and it also drops every time the user marks
 *     something read. Announcing all of that would narrate the user's own
 *     clicking back at them. Only *new* work is news.
 *  3. **Debounced.** At most one announcement per polling interval, so a burst
 *     of activity cannot queue up several utterances that then play over each
 *     other and over the user.
 *
 * The first observed value is never announced: the count arriving after sign-in
 * is not an event, it is the starting state, and announcing it would talk over
 * the page the user just opened.
 */
export function UnreadAnnouncer({
  unreadCount,
  minIntervalMs = UNREAD_POLL_INTERVAL_MS,
}: UnreadAnnouncerProps) {
  const [message, setMessage] = useState('')
  const previous = useRef<number | null>(null)
  const lastAnnouncedAt = useRef(0)

  useEffect(() => {
    if (unreadCount === undefined) return

    const before = previous.current
    previous.current = unreadCount

    // The starting state, not an event.
    if (before === null) return
    if (unreadCount <= before) return

    const now = Date.now()
    if (now - lastAnnouncedAt.current < minIntervalMs) return
    lastAnnouncedAt.current = now

    setMessage(unreadCount === 1 ? '1 unread notification' : `${unreadCount} unread notifications`)
  }, [unreadCount, minIntervalMs])

  return (
    <span role="status" aria-live="polite" className="sr-only">
      {message}
    </span>
  )
}
