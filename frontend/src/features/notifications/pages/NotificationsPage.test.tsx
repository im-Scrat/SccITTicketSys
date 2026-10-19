import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationsPage from './NotificationsPage'
import type { AppNotification, Paginated } from '../types'

const fetchNotifications = vi.hoisted(() => vi.fn())
const fetchUnreadCount = vi.hoisted(() => vi.fn())
const fetchNotificationPreferences = vi.hoisted(() => vi.fn())
const markNotificationRead = vi.hoisted(() => vi.fn())
const markNotificationUnread = vi.hoisted(() => vi.fn())
const markAllNotificationsRead = vi.hoisted(() => vi.fn())

vi.mock('../api/notificationsApi', () => ({
  fetchNotifications,
  fetchUnreadCount,
  fetchNotificationPreferences,
  markNotificationRead,
  markNotificationUnread,
  markAllNotificationsRead,
  updateNotificationPreferences: vi.fn(),
}))

/**
 * The notification centre (SRS FR-NOT-001/004/005).
 *
 * The property under test throughout is that **the server does the filtering
 * and the paging**. Each assertion below checks the parameters the page asked
 * for, not the rows it happened to render — a page that fetched everything and
 * sliced it in the browser would pass a rows-only test and fail in production
 * on the twenty-first notification.
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

function page(
  rows: AppNotification[],
  meta: Partial<Paginated<AppNotification>['meta']> = {},
): Paginated<AppNotification> {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: rows.length,
      from: rows.length === 0 ? null : 1,
      to: rows.length === 0 ? null : rows.length,
      ...meta,
    },
  }
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  )

  return render(<NotificationsPage />, { wrapper })
}

beforeEach(() => {
  vi.clearAllMocks()
  fetchNotifications.mockResolvedValue(page([notification()]))
  fetchUnreadCount.mockResolvedValue(1)
  fetchNotificationPreferences.mockResolvedValue({
    data: [],
    meta: {
      channels: [
        { value: 'in_app', label: 'In App' },
        { value: 'email', label: 'Email' },
      ],
      types: [
        { value: 'assignment', label: 'Assignment' },
        { value: 'ticket_update', label: 'Ticket Update' },
      ],
      default_enabled: true,
    },
  })
})

describe('the centre', () => {
  it('lists the caller’s notifications under a focusable heading', async () => {
    renderPage()

    const heading = await screen.findByRole('heading', { name: 'Notifications', level: 1 })
    // Focus moves here on entry so a keyboard user is not left at the top of
    // the document after following "See all" from the panel.
    await waitFor(() => expect(heading).toHaveFocus())

    expect(await screen.findByText('Ticket TKT-000123 assigned to you')).toBeInTheDocument()
  })

  it('states the unread count in the lead', async () => {
    fetchUnreadCount.mockResolvedValue(4)
    renderPage()

    expect(await screen.findByText(/4 unread\./)).toBeInTheDocument()
  })

  it('says so plainly when nothing is waiting', async () => {
    fetchUnreadCount.mockResolvedValue(0)
    renderPage()

    expect(await screen.findByText(/you have read everything/i)).toBeInTheDocument()
  })
})

describe('filtering', () => {
  it('asks the server for unread only, and resets to the first page', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('Ticket TKT-000123 assigned to you')
    await user.click(screen.getByRole('tab', { name: /unread/i }))

    await waitFor(() =>
      expect(fetchNotifications).toHaveBeenCalledWith({ unread: true, type: null, page: 1 }),
    )
  })

  it('asks the server for one type, and never filters in the browser', async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText('Ticket TKT-000123 assigned to you')
    await user.selectOptions(screen.getByLabelText(/filter by type/i), 'assignment')

    await waitFor(() =>
      expect(fetchNotifications).toHaveBeenCalledWith({
        unread: false,
        type: 'assignment',
        page: 1,
      }),
    )
  })

  it('builds the type list from the server vocabulary, not a hard-coded one', async () => {
    renderPage()

    const select = await screen.findByLabelText(/filter by type/i)
    // The select renders before the vocabulary arrives — it holds only "All
    // types" until `meta.types` resolves, which is the correct intermediate
    // state and the reason this waits rather than asserting immediately.
    await screen.findByRole('option', { name: 'Ticket Update' })

    const options = within(select)
      .getAllByRole('option')
      .map((option) => option.textContent)

    expect(options).toEqual(['All types', 'Assignment', 'Ticket Update'])
  })
})

describe('pagination', () => {
  it('pages through the server’s envelope', async () => {
    const user = userEvent.setup()
    fetchNotifications.mockResolvedValue(
      page([notification()], { current_page: 1, last_page: 3, total: 44, from: 1, to: 20 }),
    )
    renderPage()

    expect(await screen.findByText(/Page 1 of 3/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /next page/i }))

    await waitFor(() =>
      expect(fetchNotifications).toHaveBeenCalledWith({ unread: false, type: null, page: 2 }),
    )
  })

  it('shows the window the server reported', async () => {
    fetchNotifications.mockResolvedValue(
      page([notification()], { current_page: 2, last_page: 3, total: 44, from: 21, to: 40 }),
    )
    renderPage()

    expect(await screen.findByText('21')).toBeInTheDocument()
    expect(await screen.findByText('44')).toBeInTheDocument()
  })
})

describe('marking everything read', () => {
  it('clears a small inbox immediately, without a confirmation', async () => {
    const user = userEvent.setup()
    markAllNotificationsRead.mockResolvedValue(1)
    renderPage()

    await screen.findByText('Ticket TKT-000123 assigned to you')
    await user.click(screen.getByRole('button', { name: /mark all as read/i }))

    await waitFor(() => expect(markAllNotificationsRead).toHaveBeenCalledTimes(1))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('asks first when the inbox is large enough that the click could be a mistake', async () => {
    const user = userEvent.setup()
    fetchUnreadCount.mockResolvedValue(37)
    renderPage()

    await screen.findByText('Ticket TKT-000123 assigned to you')
    await user.click(screen.getByRole('button', { name: /mark all as read/i }))

    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    expect(markAllNotificationsRead).not.toHaveBeenCalled()

    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: /mark all as read/i }),
    )
    await waitFor(() => expect(markAllNotificationsRead).toHaveBeenCalledTimes(1))
  })

  it('offers nothing to clear when there is nothing unread', async () => {
    fetchUnreadCount.mockResolvedValue(0)
    renderPage()

    expect(await screen.findByRole('button', { name: /mark all as read/i })).toBeDisabled()
  })
})

describe('when the list fails', () => {
  it('offers a retry rather than an empty page that looks like an empty inbox', async () => {
    fetchNotifications.mockRejectedValue(new Error('500'))
    renderPage()

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
  })
})
