import { CheckCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Pagination } from '@/components/ui/Pagination'
import { Surface } from '@/components/ui/Surface'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { NotificationFilters } from '../components/NotificationFilters'
import { NotificationList } from '../components/NotificationList'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useMarkNotificationUnread,
} from '../hooks/mutations'
import { useNotificationPreferences, useNotifications, useUnreadCount } from '../hooks/queries'

/** Above this many unread, clearing the inbox is worth a second look. */
const CONFIRM_MARK_ALL_ABOVE = 20

/**
 * The notification centre (SRS FR-NOT-001/004/005).
 *
 * The full history, with the filters and the pagination the header panel cannot
 * offer. It shares every hook and the row component with that panel, so the two
 * surfaces cannot disagree about what a notification is or what marking one
 * read does — the panel is a different container around the same list, not a
 * second implementation.
 *
 * **No `RequirePermission` wrapper, deliberately.** Every authenticated user
 * has notifications and no `notifications.*` permission exists; inventing a
 * guard here would imply a permission WP-2.7a chose not to create, and would
 * change the Client's §8.4 matrix by accident. Ownership is the boundary, it is
 * enforced server-side on every one of the eight endpoints, and this page sends
 * no identifier it could enforce anything with.
 */
export default function NotificationsPage() {
  useDocumentMeta({ title: 'Notifications' })

  const [unread, setUnread] = useState(false)
  const [type, setType] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [confirmingMarkAll, setConfirmingMarkAll] = useState(false)

  const filters = { unread, type, page }
  const notifications = useNotifications(filters)
  const unreadCount = useUnreadCount()
  const preferences = useNotificationPreferences()

  const markRead = useMarkNotificationRead()
  const markUnread = useMarkNotificationUnread()
  const markAllRead = useMarkAllNotificationsRead()

  const heading = useRef<HTMLHeadingElement>(null)

  /**
   * Move focus to the heading when the page is opened.
   *
   * A keyboard or screen-reader user arriving from the panel's "See all" is
   * otherwise left with focus on a control that no longer exists, and the next
   * Tab starts from the top of the document. `tabIndex={-1}` makes the heading
   * focusable without adding it to the tab order.
   */
  useEffect(() => {
    heading.current?.focus()
  }, [])

  const rows = notifications.data?.data ?? []
  const meta = notifications.data?.meta
  const typeOptions = preferences.data?.meta.types ?? []
  const unreadTotal = unreadCount.data ?? 0

  /** Reset to the first page whenever the filter changes beneath it. */
  const changeUnread = (next: boolean) => {
    setUnread(next)
    setPage(1)
  }
  const changeType = (next: string | null) => {
    setType(next)
    setPage(1)
  }

  const clearInbox = () => {
    markAllRead.mutate()
    setConfirmingMarkAll(false)
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1
            ref={heading}
            tabIndex={-1}
            className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong outline-none"
          >
            Notifications
          </h1>
          <p className="mt-1 text-sm text-muted">
            Everything the system has told you, newest first.{' '}
            {unreadTotal > 0
              ? `${unreadTotal} unread.`
              : 'Nothing is waiting — you have read everything.'}
          </p>
        </div>

        <Button
          variant="secondary"
          size="sm"
          leftIcon={<CheckCheck size={18} aria-hidden="true" />}
          disabled={unreadTotal === 0 || markAllRead.isPending}
          loading={markAllRead.isPending}
          onClick={() =>
            unreadTotal > CONFIRM_MARK_ALL_ABOVE ? setConfirmingMarkAll(true) : clearInbox()
          }
        >
          Mark all as read
        </Button>
      </div>

      <NotificationFilters
        unread={unread}
        onUnreadChange={changeUnread}
        type={type}
        onTypeChange={changeType}
        typeOptions={typeOptions}
        unreadCount={unreadTotal}
      />

      <Surface className="overflow-hidden">
        <NotificationList
          label="Notifications"
          notifications={rows}
          isLoading={notifications.isPending}
          isError={notifications.isError}
          onRetry={() => void notifications.refetch()}
          typeOptions={typeOptions}
          onMarkRead={(id) => markRead.mutate(id)}
          onMarkUnread={(id) => markUnread.mutate(id)}
          emptyTitle={unread ? 'Nothing unread' : 'No notifications yet'}
          emptyDescription={
            unread
              ? 'Everything addressed to you has been read. Switch to All to see the history.'
              : 'When a ticket you are involved in moves, or maintenance is scheduled for you, it appears here.'
          }
        />

        {meta && (
          <Pagination
            page={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            from={meta.from}
            to={meta.to}
            onPage={setPage}
          />
        )}
      </Surface>

      <ConfirmDialog
        open={confirmingMarkAll}
        onClose={() => setConfirmingMarkAll(false)}
        onConfirm={clearInbox}
        title="Mark everything as read?"
        description={`This marks all ${unreadTotal} unread notifications as read. Nothing is deleted — you can set any of them back to unread afterwards.`}
        confirmLabel="Mark all as read"
        loading={markAllRead.isPending}
      />
    </div>
  )
}
