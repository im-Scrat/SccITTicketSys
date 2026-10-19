import { useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query'
import {
  markAllNotificationsRead,
  markNotificationRead,
  markNotificationUnread,
  updateNotificationPreferences,
} from '../api/notificationsApi'
import type {
  AppNotification,
  NotificationPreference,
  NotificationPreferenceMatrix,
  Paginated,
} from '../types'
import { notificationKeys } from './queries'

/**
 * The three writes the notification centre performs, and the preference save.
 *
 * ── Why these are optimistic when the rest of the app is not ───────────────
 *
 * Elsewhere a mutation invalidates and waits, because the server's answer can
 * differ from the client's guess — approving a support request reschedules
 * maintenance and may put a ticket on hold, and a hand-written cache patch
 * would be a second, quieter model of that. Marking a notification read has no
 * such tail: it stamps one timestamp on one row the caller already owns, and
 * the answer is never a surprise. What it does have is a reader clicking down a
 * list, for whom a round-trip per row is the whole experience.
 *
 * The rollback is the part that must not be skipped. An optimistic update
 * without one leaves the badge and the list asserting something the server
 * never agreed to, and the user's next refresh silently contradicts them.
 *
 * ── Every write refreshes both keys ────────────────────────────────────────
 *
 * Marking read changes the list *and* the count. A cleared list beside a badge
 * still reading "6" is the most visible bug this feature could ship, so the
 * invalidation below is unconditional and covers both — including after a
 * failure, where the server's truth is the only thing worth trusting.
 */

/** Every cached page of the list, as key/page pairs. */
type ListSnapshot = [readonly unknown[], Paginated<AppNotification> | undefined][]

interface ReadSnapshot {
  lists: ListSnapshot
  unread: number | undefined
}

/**
 * Stop in-flight reads before writing to their cache.
 *
 * Without this, a response that left the server before the optimistic patch can
 * land after it and overwrite the patch with pre-write data — the row flicks
 * back to unread for one poll interval and looks like the click was lost.
 */
async function takeSnapshot(queryClient: QueryClient): Promise<ReadSnapshot> {
  await queryClient.cancelQueries({ queryKey: notificationKeys.all })

  return {
    lists: queryClient.getQueriesData<Paginated<AppNotification>>({
      queryKey: notificationKeys.lists(),
    }),
    unread: queryClient.getQueryData<number>(notificationKeys.unreadCount()),
  }
}

function restore(queryClient: QueryClient, snapshot: ReadSnapshot | undefined): void {
  if (!snapshot) return

  for (const [key, page] of snapshot.lists) {
    queryClient.setQueryData(key, page)
  }
  queryClient.setQueryData(notificationKeys.unreadCount(), snapshot.unread)
}

/** Refresh the list and the badge together. Never one without the other. */
function invalidateBoth(queryClient: QueryClient): void {
  void queryClient.invalidateQueries({ queryKey: notificationKeys.lists() })
  void queryClient.invalidateQueries({ queryKey: notificationKeys.unreadCount() })
}

/**
 * Apply a patch to one notification wherever it is cached.
 *
 * A notification can sit in several cached pages at once — the panel's first
 * page, the centre's unread tab, a type-filtered view — and patching only the
 * one the click came from would leave the others contradicting it.
 */
function patchEverywhere(
  queryClient: QueryClient,
  id: string,
  patch: Partial<AppNotification>,
): void {
  queryClient.setQueriesData<Paginated<AppNotification>>(
    { queryKey: notificationKeys.lists() },
    (page) =>
      page === undefined
        ? page
        : {
            ...page,
            data: page.data.map((row) => (row.id === id ? { ...row, ...patch } : row)),
          },
  )
}

/** Nudge the cached badge count, never below zero. */
function adjustUnread(queryClient: QueryClient, delta: number): void {
  queryClient.setQueryData<number>(notificationKeys.unreadCount(), (count) =>
    count === undefined ? count : Math.max(0, count + delta),
  )
}

/** Mark one notification read (FR-NOT-004). */
export function useMarkNotificationRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => markNotificationRead(id),
    onMutate: async (id) => {
      const snapshot = await takeSnapshot(queryClient)

      // Only decrement for a row that was actually unread: re-reading something
      // already read must not walk the badge down past the truth.
      const wasUnread = snapshot.lists.some(([, page]) =>
        page?.data.some((row) => row.id === id && !row.is_read),
      )

      patchEverywhere(queryClient, id, { is_read: true, read_at: new Date().toISOString() })
      if (wasUnread) adjustUnread(queryClient, -1)

      return snapshot
    },
    onError: (_error, _id, snapshot) => restore(queryClient, snapshot),
    onSettled: () => invalidateBoth(queryClient),
  })
}

/** Restore one notification to unread (FR-NOT-004). */
export function useMarkNotificationUnread() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => markNotificationUnread(id),
    onMutate: async (id) => {
      const snapshot = await takeSnapshot(queryClient)

      const wasRead = snapshot.lists.some(([, page]) =>
        page?.data.some((row) => row.id === id && row.is_read),
      )

      patchEverywhere(queryClient, id, { is_read: false, read_at: null })
      if (wasRead) adjustUnread(queryClient, 1)

      return snapshot
    },
    onError: (_error, _id, snapshot) => restore(queryClient, snapshot),
    onSettled: () => invalidateBoth(queryClient),
  })
}

/** Mark every unread notification read (FR-NOT-004, bulk). */
export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => markAllNotificationsRead(),
    onMutate: async () => {
      const snapshot = await takeSnapshot(queryClient)
      const readAt = new Date().toISOString()

      queryClient.setQueriesData<Paginated<AppNotification>>(
        { queryKey: notificationKeys.lists() },
        (page) =>
          page === undefined
            ? page
            : {
                ...page,
                data: page.data.map((row) =>
                  row.is_read ? row : { ...row, is_read: true, read_at: readAt },
                ),
              },
      )
      queryClient.setQueryData<number>(notificationKeys.unreadCount(), 0)

      return snapshot
    },
    onError: (_error, _variables, snapshot) => restore(queryClient, snapshot),
    onSettled: () => invalidateBoth(queryClient),
  })
}

/**
 * Save the changed preference cells in one request (FR-NOT-002).
 *
 * Not optimistic. The matrix is a form with an explicit save, so the user is
 * already waiting on an answer, and a checkbox that ticks itself before the
 * server agrees is exactly the ambiguity an explicit save exists to remove.
 * The returned rows are merged into the cached matrix so the `meta` vocabulary
 * — which a save cannot change — survives without a second round-trip.
 */
export function useUpdateNotificationPreferences() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (preferences: NotificationPreference[]) =>
      updateNotificationPreferences(preferences),
    onSuccess: (rows) => {
      queryClient.setQueryData<NotificationPreferenceMatrix>(
        notificationKeys.preferences(),
        (matrix) => (matrix === undefined ? matrix : { ...matrix, data: rows }),
      )
    },
  })
}
