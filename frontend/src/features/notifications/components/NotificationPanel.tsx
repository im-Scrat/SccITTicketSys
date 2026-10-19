import { CheckCheck } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { Link } from 'react-router-dom'
import { NotificationList } from './NotificationList'
import type { AppNotification, Option } from '../types'

interface NotificationPanelProps {
  id: string
  notifications: AppNotification[]
  isLoading: boolean
  isError: boolean
  onRetry: () => void
  typeOptions?: Option[]
  unreadCount: number
  onMarkRead: (id: string) => void
  onMarkUnread: (id: string) => void
  onMarkAllRead: () => void
  markAllPending: boolean
  onClose: () => void
}

/**
 * The glance (SRS FR-NOT-001).
 *
 * The dominant case for this feature is an interrupted person checking whether
 * anything needs them — a technician between jobs, an administrator mid-triage.
 * That case is badly served by a link that throws the reader onto another page,
 * so the panel answers it in place and the centre stays available for triage
 * and search-back. Both are the same hooks and the same row component; this is
 * a container, not a second implementation.
 *
 * ── Non-modal on purpose ───────────────────────────────────────────────────
 *
 * This is a disclosure, not a dialog that owns the screen. It has no backdrop,
 * it does not lock scrolling, and above all **it does not trap focus** — a
 * focus trap here would be an NFR-ACC-003 failure, and a keyboard trap in the
 * header is a trap on every authenticated screen in the product. Tab moves out
 * of the panel naturally and closes it; Escape closes it and returns focus to
 * the bell, which the owning menu handles because it holds the bell's ref.
 *
 * Focus moves *in* on open — to the panel itself rather than to its first
 * control, so a screen reader reads the panel's name and its contents from the
 * top rather than starting mid-way at "Mark all as read".
 */
export function NotificationPanel({
  id,
  notifications,
  isLoading,
  isError,
  onRetry,
  typeOptions,
  unreadCount,
  onMarkRead,
  onMarkUnread,
  onMarkAllRead,
  markAllPending,
  onClose,
}: NotificationPanelProps) {
  const panel = useRef<HTMLDivElement>(null)

  useEffect(() => {
    panel.current?.focus()
  }, [])

  return (
    <div
      ref={panel}
      id={id}
      role="dialog"
      aria-label="Notifications"
      tabIndex={-1}
      className={[
        // Narrow screens: a sheet pinned under the header, never a 22rem panel
        // hanging off the right edge of a 320px viewport.
        'fixed inset-x-2 top-[4.5rem] z-30 flex max-h-[75vh] flex-col overflow-hidden outline-none',
        'rounded-lg border-2 border-border bg-surface shadow-[var(--shadow-overlay-md)]',
        'animate-sheet-in',
        // From `sm` up it anchors to the bell.
        'sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[24rem]',
      ].join(' ')}
    >
      <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
        <h2 className="text-sm font-semibold text-ink-strong">
          Notifications
          {unreadCount > 0 && (
            <span className="ml-1.5 font-medium text-muted tnum">({unreadCount} unread)</span>
          )}
        </h2>

        <button
          type="button"
          onClick={onMarkAllRead}
          disabled={unreadCount === 0 || markAllPending}
          className={[
            'inline-flex items-center gap-1.5 rounded-sm px-2 py-1 text-xs font-semibold text-muted',
            'hover:bg-surface-sunken hover:text-ink disabled:cursor-not-allowed disabled:text-faint disabled:hover:bg-transparent',
          ].join(' ')}
        >
          <CheckCheck size={15} aria-hidden="true" />
          Mark all as read
        </button>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        <NotificationList
          label="Recent notifications"
          notifications={notifications}
          isLoading={isLoading}
          isError={isError}
          onRetry={onRetry}
          typeOptions={typeOptions}
          onMarkRead={onMarkRead}
          onMarkUnread={onMarkUnread}
          onNavigate={onClose}
          compact
          emptyTitle="You are all caught up"
          emptyDescription="New notifications about your tickets and maintenance appear here."
        />
      </div>

      <div className="border-t border-border px-4 py-2.5">
        <Link
          to="/app/notifications"
          onClick={onClose}
          className="text-xs font-semibold text-primary-strong underline-offset-2 hover:underline"
        >
          See all notifications
        </Link>
      </div>
    </div>
  )
}
