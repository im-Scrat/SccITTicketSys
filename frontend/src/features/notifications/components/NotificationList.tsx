import { BellOff } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { NotificationRow } from './NotificationRow'
import type { AppNotification, Option } from '../types'

interface NotificationListProps {
  notifications: AppNotification[]
  isLoading: boolean
  isError: boolean
  onRetry: () => void
  typeOptions?: Option[]
  onMarkRead: (id: string) => void
  onMarkUnread: (id: string) => void
  onNavigate?: () => void
  compact?: boolean
  /** What "nothing here" means for this particular filter. */
  emptyTitle?: string
  emptyDescription?: string
  /** Labels the list for assistive technology; both containers name their own. */
  label: string
}

/**
 * The list, and the three states it can be in besides "has rows"
 * (SRS FR-NOT-001, NFR-USB-005).
 *
 * A `<ul>` of `<li>`s rather than a table: these are items, not records with
 * shared columns, and a screen reader announcing "list, 12 items" is the
 * correct summary of what this is. A table would promise columns that do not
 * exist and would make each row's single link harder to reach.
 *
 * Loading is skeleton rows, not a spinner in the middle of the panel — the
 * shape of what is coming, so the layout does not jump when it lands. The empty
 * state teaches rather than saying "no data": what appears here, and why it is
 * empty right now. The error state offers the retry, because a failed fetch on
 * a polled surface is usually transient and a dead end here would send the user
 * to reload the whole application.
 */
export function NotificationList({
  notifications,
  isLoading,
  isError,
  onRetry,
  typeOptions,
  onMarkRead,
  onMarkUnread,
  onNavigate,
  compact = false,
  emptyTitle = 'No notifications yet',
  emptyDescription = 'When a ticket you are involved in moves, or maintenance is scheduled for you, it appears here.',
  label,
}: NotificationListProps) {
  if (isError) {
    return (
      <div className="p-4">
        <Alert tone="error" title="Notifications could not be loaded">
          <p>The list did not load. Your notifications are safe — this is a display problem.</p>
          <Button type="button" variant="secondary" size="sm" className="mt-3" onClick={onRetry}>
            Try again
          </Button>
        </Alert>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="divide-y divide-border" role="status" aria-label="Loading notifications">
        {Array.from({ length: compact ? 3 : 5 }).map((_, index) => (
          <div key={index} className="flex gap-3 px-4 py-3.5">
            <Skeleton circle width={10} height={10} className="mt-2 shrink-0" />
            <div className="min-w-0 flex-1 space-y-2">
              <Skeleton height={12} width="65%" />
              <Skeleton height={10} width="85%" />
              <Skeleton height={9} width={72} />
            </div>
          </div>
        ))}
      </div>
    )
  }

  if (notifications.length === 0) {
    return (
      <div className="p-4">
        <EmptyState
          icon={<BellOff size={22} />}
          title={emptyTitle}
          description={emptyDescription}
          className="border-0 bg-transparent py-8"
        />
      </div>
    )
  }

  return (
    <ul aria-label={label} className="divide-y divide-border">
      {notifications.map((notification) => (
        <NotificationRow
          key={notification.id}
          notification={notification}
          typeOptions={typeOptions}
          onMarkRead={onMarkRead}
          onMarkUnread={onMarkUnread}
          onNavigate={onNavigate}
          compact={compact}
        />
      ))}
    </ul>
  )
}
