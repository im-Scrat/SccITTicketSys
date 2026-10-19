import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import type { Option } from '../types'

interface NotificationFiltersProps {
  unread: boolean
  onUnreadChange: (unread: boolean) => void
  type: string | null
  onTypeChange: (type: string | null) => void
  /** The server's type vocabulary. Never a hard-coded list. */
  typeOptions: Option[]
  unreadCount?: number
}

/**
 * The two filters FR-NOT-005 names, and only those two.
 *
 * Both drive the **query**, not a client-side `filter()`. The endpoint already
 * validates `unread` and `type`, and a browser-side filter would be a second
 * definition of the same rule — one that disagrees the moment the list is
 * longer than a page, because it would be filtering twenty rows out of a
 * mailbox of hundreds and calling the result "all".
 *
 * The type list comes from `GET /api/notification-preferences`'s `meta.types`,
 * which is the same vocabulary the preference matrix renders. So a type added
 * to the server-side enum — D5's announcements, WP-2.4b's procurement — appears
 * in this select without a frontend change, and no list here can fall behind an
 * enum it cannot see.
 */
export function NotificationFilters({
  unread,
  onUnreadChange,
  type,
  onTypeChange,
  typeOptions,
  unreadCount,
}: NotificationFiltersProps) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <Tabs
        tabs={[
          { value: 'all', label: 'All' },
          // The count sits on the tab it describes, so "Unread 3" is one fact
          // in one place rather than a number to reconcile with the badge.
          { value: 'unread', label: 'Unread', count: unreadCount },
        ]}
        value={unread ? 'unread' : 'all'}
        onChange={(value) => onUnreadChange(value === 'unread')}
        className="sm:flex-1"
      />

      <div className="sm:w-64">
        <label
          htmlFor="notification-type"
          className="mb-1.5 block text-xs font-semibold text-muted"
        >
          Filter by type
        </label>
        <Select
          id="notification-type"
          value={type ?? ''}
          onChange={(event) => onTypeChange(event.target.value === '' ? null : event.target.value)}
          className="h-12"
        >
          <option value="">All types</option>
          {typeOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </div>
    </div>
  )
}
