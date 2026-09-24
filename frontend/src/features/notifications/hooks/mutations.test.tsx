import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useMarkNotificationUnread,
  useUpdateNotificationPreferences,
} from './mutations'
import { notificationKeys } from './queries'
import type { AppNotification, NotificationPreferenceMatrix, Paginated } from '../types'

const markNotificationRead = vi.hoisted(() => vi.fn())
const markNotificationUnread = vi.hoisted(() => vi.fn())
const markAllNotificationsRead = vi.hoisted(() => vi.fn())
const updateNotificationPreferences = vi.hoisted(() => vi.fn())

vi.mock('../api/notificationsApi', () => ({
  markNotificationRead,
  markNotificationUnread,
  markAllNotificationsRead,
  updateNotificationPreferences,
}))

/**
 * The one place the notification UI writes (SRS FR-NOT-004).
 *
 * Three properties are protected here, and each of them is a bug that would
 * ship silently without a test:
 *
 *  1. **The badge and the list never disagree.** Marking read changes both, so
 *     both are patched and both are invalidated. A cleared list beside a badge
 *     still reading "2" is the most visible failure this feature has.
 *  2. **A failed write leaves nothing behind.** An optimistic update without a
 *     rollback tells the user something the server refused, and the next
 *     refresh quietly contradicts it.
 *  3. **The count is nudged by what actually changed.** Re-reading a row that
 *     was already read must not walk the badge below the truth.
 */

function notification(overrides: Partial<AppNotification> = {}): AppNotification {
  return {
    id: 'n-1',
    type: 'assignment',
    topic: 'ticket.assigned',
    title: 'Ticket TKT-000123 assigned to you',
    message: 'Projector in Laboratory 4 will not power on',
    payload: {},
    action_url: '/app/tickets/abc',
    is_read: false,
    read_at: null,
    created_at: '2026-09-05T08:00:00+00:00',
    ...overrides,
  }
}

function page(rows: AppNotification[]): Paginated<AppNotification> {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: rows.length,
      from: rows.length === 0 ? null : 1,
      to: rows.length === 0 ? null : rows.length,
    },
  }
}

/** A client seeded with one cached page and a matching badge count. */
function setup(rows: AppNotification[], unread: number) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  client.setQueryData(notificationKeys.list({}), page(rows))
  client.setQueryData(notificationKeys.unreadCount(), unread)

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  )

  const cachedRows = () =>
    client.getQueryData<Paginated<AppNotification>>(notificationKeys.list({}))
  const cachedCount = () => client.getQueryData<number>(notificationKeys.unreadCount())

  return { client, wrapper, cachedRows, cachedCount }
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('marking one read', () => {
  it('updates the row and the badge before the server answers', async () => {
    let resolve: (value: unknown) => void = () => {}
    markNotificationRead.mockReturnValue(
      new Promise((r) => {
        resolve = r
      }),
    )

    const { wrapper, cachedRows, cachedCount } = setup([notification()], 1)
    const { result } = renderHook(() => useMarkNotificationRead(), { wrapper })

    result.current.mutate('n-1')

    await waitFor(() => expect(cachedRows()?.data[0].is_read).toBe(true))
    // The badge moved with it, in the same tick — not after the round-trip.
    expect(cachedCount()).toBe(0)

    resolve({})
  })

  it('rolls the row and the badge back when the server refuses', async () => {
    markNotificationRead.mockRejectedValue(new Error('500'))

    const { wrapper, cachedRows, cachedCount } = setup([notification()], 1)
    const { result } = renderHook(() => useMarkNotificationRead(), { wrapper })

    result.current.mutate('n-1')

    await waitFor(() => expect(result.current.isError).toBe(true))
    expect(cachedRows()?.data[0].is_read).toBe(false)
    expect(cachedCount()).toBe(1)
  })

  it('does not decrement the badge for a row that was already read', async () => {
    markNotificationRead.mockResolvedValue(notification({ is_read: true }))

    const { wrapper, cachedCount } = setup([notification({ is_read: true, read_at: 'x' })], 3)
    const { result } = renderHook(() => useMarkNotificationRead(), { wrapper })

    result.current.mutate('n-1')

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(cachedCount()).toBe(3)
  })

  it('refreshes the list and the count together, never one alone', async () => {
    markNotificationRead.mockResolvedValue(notification({ is_read: true }))

    const { client, wrapper } = setup([notification()], 1)
    const invalidate = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => useMarkNotificationRead(), { wrapper })

    result.current.mutate('n-1')

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    const invalidated = invalidate.mock.calls.map(([options]) => JSON.stringify(options?.queryKey))
    expect(invalidated).toContain(JSON.stringify(notificationKeys.lists()))
    expect(invalidated).toContain(JSON.stringify(notificationKeys.unreadCount()))
  })
})

describe('restoring one to unread', () => {
  it('raises the badge and clears the read stamp', async () => {
    markNotificationUnread.mockResolvedValue(notification())

    const { wrapper, cachedRows, cachedCount } = setup(
      [notification({ is_read: true, read_at: '2026-09-05T09:00:00+00:00' })],
      0,
    )
    const { result } = renderHook(() => useMarkNotificationUnread(), { wrapper })

    result.current.mutate('n-1')

    await waitFor(() => expect(cachedRows()?.data[0].is_read).toBe(false))
    expect(cachedRows()?.data[0].read_at).toBeNull()
    expect(cachedCount()).toBe(1)
  })

  it('rolls back a refused restore', async () => {
    markNotificationUnread.mockRejectedValue(new Error('500'))

    const { wrapper, cachedRows, cachedCount } = setup(
      [notification({ is_read: true, read_at: '2026-09-05T09:00:00+00:00' })],
      0,
    )
    const { result } = renderHook(() => useMarkNotificationUnread(), { wrapper })

    result.current.mutate('n-1')

    await waitFor(() => expect(result.current.isError).toBe(true))
    expect(cachedRows()?.data[0].is_read).toBe(true)
    expect(cachedCount()).toBe(0)
  })
})

describe('marking everything read', () => {
  it('clears every unread row and zeroes the badge in one pass', async () => {
    let resolve: (value: unknown) => void = () => {}
    markAllNotificationsRead.mockReturnValue(
      new Promise((r) => {
        resolve = r
      }),
    )

    const { wrapper, cachedRows, cachedCount } = setup(
      [
        notification({ id: 'n-1' }),
        notification({ id: 'n-2' }),
        notification({ id: 'n-3', is_read: true, read_at: '2026-09-05T09:00:00+00:00' }),
      ],
      2,
    )
    const { result } = renderHook(() => useMarkAllNotificationsRead(), { wrapper })

    result.current.mutate()

    await waitFor(() => expect(cachedCount()).toBe(0))
    expect(cachedRows()?.data.every((row) => row.is_read)).toBe(true)

    resolve(2)
  })

  it('restores the whole page when the bulk write fails', async () => {
    markAllNotificationsRead.mockRejectedValue(new Error('500'))

    const { wrapper, cachedRows, cachedCount } = setup(
      [notification({ id: 'n-1' }), notification({ id: 'n-2' })],
      2,
    )
    const { result } = renderHook(() => useMarkAllNotificationsRead(), { wrapper })

    result.current.mutate()

    await waitFor(() => expect(result.current.isError).toBe(true))
    expect(cachedRows()?.data.every((row) => !row.is_read)).toBe(true)
    expect(cachedCount()).toBe(2)
  })
})

describe('saving preferences', () => {
  it('merges the saved rows into the matrix without discarding its vocabulary', async () => {
    const saved = [{ channel: 'email', notification_type: 'assignment', is_enabled: false }]
    updateNotificationPreferences.mockResolvedValue(saved)

    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
    client.setQueryData<NotificationPreferenceMatrix>(notificationKeys.preferences(), {
      data: [{ channel: 'email', notification_type: 'assignment', is_enabled: true }],
      meta: {
        channels: [{ value: 'email', label: 'Email' }],
        types: [{ value: 'assignment', label: 'Assignment' }],
        default_enabled: true,
      },
    })

    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    )
    const { result } = renderHook(() => useUpdateNotificationPreferences(), { wrapper })

    result.current.mutate(saved)

    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    const matrix = client.getQueryData<NotificationPreferenceMatrix>(notificationKeys.preferences())
    expect(matrix?.data[0].is_enabled).toBe(false)
    // The save response carries no `meta`; losing it here would empty the grid.
    expect(matrix?.meta.types).toHaveLength(1)
  })
})
