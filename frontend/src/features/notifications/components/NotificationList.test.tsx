import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { NotificationList } from './NotificationList'
import type { AppNotification } from '../types'

/**
 * The list and its three non-happy states (NFR-USB-005).
 *
 * Loading, empty and error are the states most often left to a later pass and
 * then never written, so each is asserted here. The empty state in particular
 * has to *teach* — DESIGN.md is explicit that "No data" is not an empty state —
 * and the error state has to offer a way forward, because a polled surface that
 * fails once will usually succeed on the next try.
 */
function notification(overrides: Partial<AppNotification> = {}): AppNotification {
  return {
    id: 'n-1',
    type: 'ticket_update',
    topic: 'ticket.status_changed',
    title: 'Ticket TKT-000123 is now in progress',
    message: null,
    payload: {},
    action_url: '/app/tickets/abc',
    is_read: false,
    read_at: null,
    created_at: '2026-09-05T08:00:00+00:00',
    ...overrides,
  }
}

function renderList(props: Partial<Parameters<typeof NotificationList>[0]> = {}) {
  const onRetry = vi.fn()

  render(
    <MemoryRouter>
      <NotificationList
        notifications={[]}
        isLoading={false}
        isError={false}
        onRetry={onRetry}
        onMarkRead={vi.fn()}
        onMarkUnread={vi.fn()}
        label="Notifications"
        {...props}
      />
    </MemoryRouter>,
  )

  return { onRetry }
}

describe('with rows', () => {
  it('renders each notification as a list item under a named list', () => {
    renderList({
      notifications: [
        notification({ id: 'n-1', title: 'First notification' }),
        notification({ id: 'n-2', title: 'Second notification' }),
      ],
    })

    const list = screen.getByRole('list', { name: 'Notifications' })
    expect(list).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(2)
    expect(screen.getByText('First notification')).toBeInTheDocument()
    expect(screen.getByText('Second notification')).toBeInTheDocument()
  })

  it('preserves the order it is given — the server decides newest-first', () => {
    renderList({
      notifications: [
        notification({ id: 'n-1', title: 'Newer' }),
        notification({ id: 'n-2', title: 'Older' }),
      ],
    })

    const titles = screen.getAllByRole('listitem').map((item) => item.textContent)
    expect(titles[0]).toContain('Newer')
    expect(titles[1]).toContain('Older')
  })
})

describe('loading', () => {
  it('shows placeholder rows rather than a spinner in the middle of the panel', () => {
    renderList({ isLoading: true })

    expect(screen.getByRole('status', { name: /loading notifications/i })).toBeInTheDocument()
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
  })
})

describe('empty', () => {
  it('teaches what will appear here instead of saying "no data"', () => {
    renderList({ notifications: [] })

    expect(screen.getByText('No notifications yet')).toBeInTheDocument()
    expect(screen.getByText(/appears here/i)).toBeInTheDocument()
  })

  it('lets the container say what empty means for the filter in force', () => {
    renderList({
      notifications: [],
      emptyTitle: 'Nothing unread',
      emptyDescription: 'Everything addressed to you has been read.',
    })

    expect(screen.getByText('Nothing unread')).toBeInTheDocument()
  })
})

describe('error', () => {
  it('says the notifications are safe and offers a retry', async () => {
    const user = userEvent.setup()
    const { onRetry } = renderList({ isError: true })

    expect(screen.getByRole('alert')).toBeInTheDocument()
    expect(screen.getByText(/display problem/i)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(onRetry).toHaveBeenCalled()
  })

  it('does not render a list alongside the error', () => {
    renderList({ isError: true, notifications: [notification()] })

    expect(screen.queryByRole('list')).not.toBeInTheDocument()
  })
})
