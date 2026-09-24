import { api } from '@/services/api'
import type {
  AppNotification,
  NotificationFilters,
  NotificationPreference,
  NotificationPreferenceMatrix,
  Paginated,
} from '../types'

/**
 * The notification centre's eight calls (SRS FR-NOT-001/002/004/005).
 *
 * All eight are session-scoped: not one of them takes a user identifier, and
 * none ever will. Ownership is the boundary WP-2.7a enforces on the server, so
 * a client-side filter would be a second, weaker copy of a rule that is already
 * absolute — and the first place the two could disagree.
 *
 * **Filtering and pagination happen on the server.** `unread` and `type` are
 * the two filters FR-NOT-005 names and the two the endpoint validates; fetching
 * the whole list to filter it in the browser would drift from the server's
 * semantics and grow without bound as a mailbox fills.
 */

/** The caller's own notifications, newest first (FR-NOT-001). */
export async function fetchNotifications(
  filters: NotificationFilters = {},
): Promise<Paginated<AppNotification>> {
  const { data } = await api.get<Paginated<AppNotification>>('/notifications', {
    params: {
      // Sent only when true: `unread=false` is a filter for "read notifications",
      // which is not what the unread tab means and not what the endpoint reads.
      ...(filters.unread ? { unread: 1 } : {}),
      ...(filters.type ? { type: filters.type } : {}),
      ...(filters.page && filters.page > 1 ? { page: filters.page } : {}),
    },
  })

  return data
}

/**
 * The unread badge count (FR-NOT-001).
 *
 * Its own endpoint because it is polled on a cadence the list is not — one
 * indexed count against `notifications_unread`, not a page of rows thrown away.
 */
export async function fetchUnreadCount(): Promise<number> {
  const { data } = await api.get<{ unread: number }>('/notifications/unread-count')

  return data.unread
}

/** Mark one read (FR-NOT-004). Idempotent server-side. */
export async function markNotificationRead(id: string): Promise<AppNotification> {
  const { data } = await api.patch<{ data: AppNotification }>(`/notifications/${id}/read`)

  return data.data
}

/** Restore one to unread (FR-NOT-004). */
export async function markNotificationUnread(id: string): Promise<AppNotification> {
  const { data } = await api.patch<{ data: AppNotification }>(`/notifications/${id}/unread`)

  return data.data
}

/** Clear the whole inbox in one statement (FR-NOT-004, bulk). Returns how many moved. */
export async function markAllNotificationsRead(): Promise<number> {
  const { data } = await api.patch<{ marked: number }>('/notifications/read-all')

  return data.marked
}

/* ------------------------------------------------------------ preferences */

/** The complete channel × type matrix, gaps already resolved by the server. */
export async function fetchNotificationPreferences(): Promise<NotificationPreferenceMatrix> {
  const { data } = await api.get<NotificationPreferenceMatrix>('/notification-preferences')

  return data
}

/**
 * Save changed cells (FR-NOT-002).
 *
 * A batch, because the screen has twenty-seven switches and one save button: the
 * endpoint accepts an array precisely so a flaky connection cannot leave half
 * the matrix written.
 */
export async function updateNotificationPreferences(
  preferences: NotificationPreference[],
): Promise<NotificationPreference[]> {
  const { data } = await api.put<{ data: NotificationPreference[] }>('/notification-preferences', {
    preferences,
  })

  // The save response carries `data` only — no `meta`. The channel and type
  // vocabulary cannot change as a result of saving a preference, so the caller
  // merges these rows into the matrix it already holds rather than this
  // function inventing a `meta` the server did not send.
  return data.data
}
