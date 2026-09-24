import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchNotificationPreferences,
  fetchNotifications,
  fetchUnreadCount,
} from '../api/notificationsApi'
import type { NotificationFilters } from '../types'

/**
 * Query keys for the notification slice (SDD §7.2).
 *
 * Namespaced under one root so a mutation can invalidate the whole tree, and
 * split below it so the badge and the list are separately addressable — the two
 * are refreshed together after every write, but only the badge is polled.
 */
export const notificationKeys = {
  all: ['notifications'] as const,
  lists: () => ['notifications', 'list'] as const,
  list: (filters: NotificationFilters = {}) =>
    [
      'notifications',
      'list',
      filters.unread ?? false,
      filters.type ?? 'all',
      filters.page ?? 1,
    ] as const,
  unreadCount: () => ['notifications', 'unread-count'] as const,
  preferences: () => ['notifications', 'preferences'] as const,
}

/**
 * How often the badge asks the server, in milliseconds.
 *
 * **60 seconds, and only while the tab is visible** (Client decision, Q4).
 * FR-NOT-007's real-time transport is P4 and out of scope, so this is a poll
 * and the UI says so rather than implying a live feed: the count can be up to
 * one interval old, which is the honest description of what it is.
 *
 * Exported so the tests assert the same number the hook uses, rather than a
 * copy of it that could drift.
 */
export const UNREAD_POLL_INTERVAL_MS = 60_000

/**
 * The caller's own notifications, server-filtered and server-paginated.
 *
 * `enabled` exists for the header panel, which must not fetch a list nobody has
 * asked to see: the badge is the standing cost of putting notifications in the
 * header, and a list on every authenticated page load would multiply that by
 * twenty rows for no one's benefit.
 */
export function useNotifications(filters: NotificationFilters = {}, enabled = true) {
  return useQuery({
    queryKey: notificationKeys.list(filters),
    queryFn: () => fetchNotifications(filters),
    enabled,
    // Changing a filter or a page keeps the previous rows on screen while the
    // next set loads, so the surface does not collapse to a skeleton and push
    // the controls the user is still holding.
    placeholderData: keepPreviousData,
  })
}

/**
 * The unread count behind the header badge (FR-NOT-001).
 *
 * Two global defaults are deliberately overridden here, and both matter:
 *
 *  - `refetchOnWindowFocus` is **false** application-wide, which is right for
 *    a table nobody expects to move on its own. It is wrong for this: coming
 *    back to the tab is exactly the moment a person looks at the badge, and a
 *    stale count then is the one failure they would notice.
 *  - `staleTime` is 30s globally; here it is the poll interval, so a component
 *    remounting (opening the panel) reads the cache instead of firing a request
 *    the timer is about to make anyway.
 *
 * `refetchIntervalInBackground` is left at its default of `false`, which is what
 * confines the poll to a visible tab — a background tab costs nothing, and the
 * focus refetch above covers the moment it stops being one.
 */
export function useUnreadCount() {
  return useQuery({
    queryKey: notificationKeys.unreadCount(),
    queryFn: fetchUnreadCount,
    refetchInterval: UNREAD_POLL_INTERVAL_MS,
    refetchOnWindowFocus: true,
    staleTime: UNREAD_POLL_INTERVAL_MS,
  })
}

/**
 * The preference matrix, and the channel/type vocabulary that renders it.
 *
 * Shared by the preferences screen and by the centre's type filter: both need
 * the server's list of types, and neither should hard-code one — a type added
 * server-side must appear in both without a frontend change (§17, D5).
 */
export function useNotificationPreferences(enabled = true) {
  return useQuery({
    queryKey: notificationKeys.preferences(),
    queryFn: fetchNotificationPreferences,
    enabled,
  })
}
