import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NotificationMenu } from './NotificationMenu'
import type { AppNotification, Paginated } from '../types'

const fetchNotifications = vi.hoisted(() => vi.fn())
const fetchUnreadCount = vi.hoisted(() => vi.fn())
const fetchNotificationPreferences = vi.hoisted(() => vi.fn())
const markAllNotificationsRead = vi.hoisted(() => vi.fn())

vi.mock('../api/notificationsApi', () => ({
  fetchNotifications,
  fetchUnreadCount,
  fetchNotificationPreferences,
  markAllNotificationsRead,
  markNotificationRead: vi.fn(),
  markNotificationUnread: vi.fn(),
  updateNotificationPreferences: vi.fn(),
}))

/**
 * The header disclosure (SRS FR-NOT-001, NFR-ACC-003).
 *
 * This component renders on **every authenticated screen in the product**, so
 * its keyboard behaviour is not a local concern: a focus trap here would be a
 * focus trap everywhere. The three properties asserted below are exactly the
 * ones that make a non-modal popover safe —
 *
 *   Escape closes it · focus returns to the bell · focus can always leave.
 *
 * The fourth is the performance one: the list must not be fetched until someone
 * opens the panel, because the alternative is a twenty-row request on every
 * page load in the application.
 */
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

function notification(overrides: Partial<AppNotification> = {}): AppNotification {
  return {
    id: 'n-1',
    type: 'assignment',
    topic: 'ticket.assigned',
    title: 'Ticket TKT-000123 assigned to you',
    message: null,
    payload: {},
    action_url: '/app/tickets/abc',
    is_read: false,
    read_at: null,
    created_at: '2026-09-05T08:00:00+00:00',
    ...overrides,
  }
}

function renderMenu() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  )

  return render(
    <div>
      <NotificationMenu />
      <button type="button">Somewhere else</button>
    </div>,
    { wrapper },
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  fetchUnreadCount.mockResolvedValue(2)
  fetchNotifications.mockResolvedValue(page([notification()]))
  fetchNotificationPreferences.mockResolvedValue({
    data: [],
    meta: {
      channels: [],
      types: [{ value: 'assignment', label: 'Assignment' }],
      default_enabled: true,
    },
  })
})

describe('opening and closing', () => {
  it('opens the panel from the bell and lists the notifications', async () => {
    const user = userEvent.setup()
    renderMenu()

    await screen.findByRole('button', { name: 'Notifications, 2 unread' })
    await user.click(screen.getByRole('button', { name: /notifications, 2 unread/i }))

    expect(await screen.findByRole('dialog', { name: 'Notifications' })).toBeInTheDocument()
    expect(await screen.findByText('Ticket TKT-000123 assigned to you')).toBeInTheDocument()
  })

  it('moves focus into the panel when it opens', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.click(await screen.findByRole('button', { name: /notifications/i }))

    const panel = await screen.findByRole('dialog', { name: 'Notifications' })
    await waitFor(() => expect(panel).toHaveFocus())
  })

  it('closes on Escape and returns focus to the bell', async () => {
    const user = userEvent.setup()
    renderMenu()

    const bell = await screen.findByRole('button', { name: /notifications/i })
    await user.click(bell)
    await screen.findByRole('dialog')

    await user.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    // Without this, the next Tab would restart from the top of the document.
    expect(bell).toHaveFocus()
  })

  it('closes when the bell is pressed a second time', async () => {
    const user = userEvent.setup()
    renderMenu()

    const bell = await screen.findByRole('button', { name: /notifications/i })
    await user.click(bell)
    await screen.findByRole('dialog')

    await user.click(bell)
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('closes when a click lands outside it', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.click(await screen.findByRole('button', { name: /notifications/i }))
    await screen.findByRole('dialog')

    await user.click(screen.getByRole('button', { name: 'Somewhere else' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('lets focus leave — there is no keyboard trap', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.click(await screen.findByRole('button', { name: /notifications/i }))
    await screen.findByRole('dialog')

    // Focus moving to a control outside the panel must be possible, and must
    // close the panel behind it rather than yanking focus back.
    act(() => {
      screen.getByRole('button', { name: 'Somewhere else' }).focus()
    })

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Somewhere else' })).toHaveFocus()
  })
})

describe('what it costs when closed', () => {
  it('polls only the count — the list is not fetched until the panel opens', async () => {
    const user = userEvent.setup()
    renderMenu()

    await screen.findByRole('button', { name: 'Notifications, 2 unread' })
    expect(fetchNotifications).not.toHaveBeenCalled()
    expect(fetchNotificationPreferences).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: /notifications/i }))

    await waitFor(() => expect(fetchNotifications).toHaveBeenCalledTimes(1))
  })
})

describe('acting from the panel', () => {
  it('clears the inbox from the panel header', async () => {
    const user = userEvent.setup()
    markAllNotificationsRead.mockResolvedValue(2)
    renderMenu()

    await user.click(await screen.findByRole('button', { name: /notifications/i }))
    await screen.findByRole('dialog')

    await user.click(screen.getByRole('button', { name: /mark all as read/i }))

    await waitFor(() => expect(markAllNotificationsRead).toHaveBeenCalledTimes(1))
  })

  it('offers the route to the full centre', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.click(await screen.findByRole('button', { name: /notifications/i }))

    expect(await screen.findByRole('link', { name: /see all notifications/i })).toHaveAttribute(
      'href',
      '/app/notifications',
    )
  })

  it('teaches rather than saying "no data" when there is nothing', async () => {
    const user = userEvent.setup()
    fetchUnreadCount.mockResolvedValue(0)
    fetchNotifications.mockResolvedValue(page([]))
    renderMenu()

    await user.click(await screen.findByRole('button', { name: 'Notifications' }))

    expect(await screen.findByText('You are all caught up')).toBeInTheDocument()
  })
})
