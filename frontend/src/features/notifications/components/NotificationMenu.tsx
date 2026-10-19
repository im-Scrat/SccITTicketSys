import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { NotificationBell } from './NotificationBell'
import { NotificationPanel } from './NotificationPanel'
import { UnreadAnnouncer } from './UnreadAnnouncer'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useMarkNotificationUnread,
} from '../hooks/mutations'
import { useNotificationPreferences, useNotifications, useUnreadCount } from '../hooks/queries'

/**
 * The header's notification entry: the bell, its badge, and the panel it opens
 * (SRS FR-NOT-001/004).
 *
 * This component owns the three things a disclosure has to get right, and each
 * of them is an accessibility requirement rather than a nicety:
 *
 *  - **Escape closes it and focus goes back to the bell.** Without the return,
 *    a keyboard user's next Tab starts from the top of the document — they are
 *    not trapped, but they are lost, which is nearly as bad.
 *  - **Tabbing out closes it.** That is what makes the absence of a focus trap
 *    correct rather than merely permissive: focus can always leave, and when it
 *    does the panel stops being open behind it.
 *  - **A click outside closes it**, on `pointerdown` rather than `click`, so
 *    the panel is gone before the thing under the pointer reacts.
 *
 * The list query is deliberately the **same key** the notification centre uses
 * for its default view, so opening the panel warms the page and marking
 * something read in one is visible in the other with no extra fetch. The panel
 * renders the server's first page as it comes: it neither slices nor re-sorts,
 * because a client-side reordering of a server-ordered list is the beginning of
 * two different answers to "what is newest".
 */
export function NotificationMenu() {
  const [open, setOpen] = useState(false)
  const panelId = useId()
  const container = useRef<HTMLDivElement>(null)
  const bell = useRef<HTMLButtonElement>(null)
  const { pathname } = useLocation()

  const unreadCount = useUnreadCount()
  // Both are fetched only once the panel has been opened. The badge is the
  // standing cost of putting notifications in the header; a twenty-row list and
  // a preference matrix on every authenticated page load are not, and this
  // component renders on every authenticated screen in the product.
  const notifications = useNotifications({}, open)
  const preferences = useNotificationPreferences(open)

  const markRead = useMarkNotificationRead()
  const markUnread = useMarkNotificationUnread()
  const markAllRead = useMarkAllNotificationsRead()

  /** Close, and put focus back where the user left it. */
  const close = useCallback((returnFocus = true) => {
    setOpen(false)
    if (returnFocus) bell.current?.focus()
  }, [])

  useEffect(() => {
    if (!open) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        close()
      }
    }

    const onPointerDown = (event: PointerEvent) => {
      if (!container.current?.contains(event.target as Node)) close(false)
    }

    /*
     * Focus leaving the panel closes it. `relatedTarget` is null when focus
     * goes to the document itself (a click on empty space, switching windows),
     * which is not a reason to close — the pointer handler above owns that
     * case, and closing here as well would steal focus back to the bell.
     */
    const onFocusOut = (event: FocusEvent) => {
      const next = event.relatedTarget as Node | null
      if (next !== null && !container.current?.contains(next)) close(false)
    }

    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    const node = container.current
    node?.addEventListener('focusout', onFocusOut)

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
      node?.removeEventListener('focusout', onFocusOut)
    }
  }, [open, close])

  /*
   * A route change closes the panel without reclaiming focus: the destination
   * page owns focus from that point, and pulling it back to the bell would
   * undo the navigation the user just made.
   */
  useEffect(() => {
    setOpen(false)
  }, [pathname])

  const unread = unreadCount.data ?? 0

  return (
    <div ref={container} className="relative">
      <NotificationBell
        ref={bell}
        unreadCount={unread}
        open={open}
        onToggle={() => (open ? close() : setOpen(true))}
        panelId={panelId}
      />

      <UnreadAnnouncer unreadCount={unreadCount.data} />

      {open && (
        <NotificationPanel
          id={panelId}
          notifications={notifications.data?.data ?? []}
          isLoading={notifications.isPending}
          isError={notifications.isError}
          onRetry={() => void notifications.refetch()}
          typeOptions={preferences.data?.meta.types}
          unreadCount={unread}
          onMarkRead={(id) => markRead.mutate(id)}
          onMarkUnread={(id) => markUnread.mutate(id)}
          onMarkAllRead={() => markAllRead.mutate()}
          markAllPending={markAllRead.isPending}
          onClose={() => close(false)}
        />
      )}
    </div>
  )
}
