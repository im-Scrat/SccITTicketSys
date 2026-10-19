import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  fetchNotificationPreferences,
  fetchNotifications,
  fetchUnreadCount,
  markAllNotificationsRead,
  markNotificationRead,
  markNotificationUnread,
  updateNotificationPreferences,
} from './notificationsApi'

const get = vi.hoisted(() => vi.fn())
const patch = vi.hoisted(() => vi.fn())
const put = vi.hoisted(() => vi.fn())

vi.mock('@/services/api', () => ({ api: { get, patch, put } }))

/**
 * The wire contract (SRS FR-NOT-001/002/004/005).
 *
 * What these assert is not "the function returns something" but the two
 * properties WP-2.7b could most plausibly get wrong:
 *
 *  1. **Filtering and pagination are the server's job.** A regression that
 *     fetched everything and filtered in the browser would still make the UI
 *     look right on a fixture of six rows, and would diverge from the server's
 *     semantics the moment a real mailbox filled up. So the params are asserted
 *     directly.
 *  2. **No request names a user.** Ownership is enforced server-side from the
 *     session; a client that started sending an identifier would be inviting a
 *     boundary that does not currently exist.
 */
beforeEach(() => {
  vi.clearAllMocks()
})

describe('reading the list', () => {
  it('asks the server to filter and paginate, and names no user', async () => {
    get.mockResolvedValue({ data: { data: [], meta: {} } })

    await fetchNotifications({ unread: true, type: 'assignment', page: 3 })

    expect(get).toHaveBeenCalledWith('/notifications', {
      params: { unread: 1, type: 'assignment', page: 3 },
    })
  })

  it('omits every filter it was not given', async () => {
    get.mockResolvedValue({ data: { data: [], meta: {} } })

    await fetchNotifications()

    expect(get).toHaveBeenCalledWith('/notifications', { params: {} })
  })

  it('does not send unread=false, which would mean something else entirely', async () => {
    // `unread` is a presence filter on the server: `unread=0` is not "read
    // notifications", it is simply falsy — but sending it invites a future
    // reader to implement the meaning it looks like it has.
    get.mockResolvedValue({ data: { data: [], meta: {} } })

    await fetchNotifications({ unread: false, page: 1 })

    expect(get).toHaveBeenCalledWith('/notifications', { params: {} })
  })

  it('unwraps the unread count', async () => {
    get.mockResolvedValue({ data: { unread: 7 } })

    await expect(fetchUnreadCount()).resolves.toBe(7)
    expect(get).toHaveBeenCalledWith('/notifications/unread-count')
  })
})

describe('writing', () => {
  it('marks one read by uuid', async () => {
    patch.mockResolvedValue({ data: { data: { id: 'n-1' } } })

    await markNotificationRead('n-1')

    expect(patch).toHaveBeenCalledWith('/notifications/n-1/read')
  })

  it('restores one to unread by uuid', async () => {
    patch.mockResolvedValue({ data: { data: { id: 'n-1' } } })

    await markNotificationUnread('n-1')

    expect(patch).toHaveBeenCalledWith('/notifications/n-1/unread')
  })

  it('clears the inbox in one request and reports how many moved', async () => {
    patch.mockResolvedValue({ data: { marked: 12 } })

    await expect(markAllNotificationsRead()).resolves.toBe(12)
    expect(patch).toHaveBeenCalledWith('/notifications/read-all')
    expect(patch).toHaveBeenCalledTimes(1)
  })
})

describe('preferences', () => {
  it('reads the whole matrix, vocabulary included', async () => {
    get.mockResolvedValue({
      data: { data: [], meta: { channels: [], types: [], default_enabled: true } },
    })

    const matrix = await fetchNotificationPreferences()

    expect(get).toHaveBeenCalledWith('/notification-preferences')
    expect(matrix.meta.default_enabled).toBe(true)
  })

  it('saves every changed cell in one batched request', async () => {
    // FR-NOT-002's reason for accepting an array: a grid of switches must not
    // become a grid of chances for a flaky connection to half-save the form.
    put.mockResolvedValue({ data: { data: [] } })

    await updateNotificationPreferences([
      { channel: 'email', notification_type: 'assignment', is_enabled: false },
      { channel: 'in_app', notification_type: 'system', is_enabled: true },
    ])

    expect(put).toHaveBeenCalledTimes(1)
    expect(put).toHaveBeenCalledWith('/notification-preferences', {
      preferences: [
        { channel: 'email', notification_type: 'assignment', is_enabled: false },
        { channel: 'in_app', notification_type: 'system', is_enabled: true },
      ],
    })
  })
})
