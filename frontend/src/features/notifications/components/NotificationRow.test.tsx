import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { NotificationRow } from './NotificationRow'
import type { AppNotification } from '../types'

/**
 * One notification row (SRS FR-NOT-001/004, NFR-ACC-004).
 *
 * The properties here are the ones a redesign would be most likely to drop, and
 * each is asserted as **text** rather than as styling — because text is what
 * survives grayscale, a colour-vision difference and a screen reader.
 *
 *  - Unread is stated in words, not only by a blue dot.
 *  - A notification with no destination renders **no link at all**. This is not
 *    a hypothetical: `account.locked` has no `action_url` by design, and a
 *    future announcement may have none either. A row that assumed one would
 *    render a link to `/null`.
 *  - The timestamp carries both readings — relative for a glance, absolute for
 *    the record.
 */
function notification(overrides: Partial<AppNotification> = {}): AppNotification {
  return {
    id: 'n-1',
    type: 'assignment',
    topic: 'ticket.assigned',
    title: 'Ticket TKT-000123 assigned to you',
    message: 'Projector in Laboratory 4 will not power on',
    payload: { ticket_number: 'TKT-000123' },
    action_url: '/app/tickets/2f1c8b0e-0000-4000-8000-000000000001',
    is_read: false,
    read_at: null,
    created_at: '2026-09-05T08:00:00+00:00',
    ...overrides,
  }
}

function renderRow(overrides: Partial<AppNotification> = {}, props = {}) {
  const onMarkRead = vi.fn()
  const onMarkUnread = vi.fn()

  render(
    <MemoryRouter>
      <ul>
        <NotificationRow
          notification={notification(overrides)}
          onMarkRead={onMarkRead}
          onMarkUnread={onMarkUnread}
          {...props}
        />
      </ul>
    </MemoryRouter>,
  )

  return { onMarkRead, onMarkUnread }
}

describe('what a row shows', () => {
  it('carries the title, the message and the type as text', () => {
    renderRow()

    expect(screen.getByText('Ticket TKT-000123 assigned to you')).toBeInTheDocument()
    expect(screen.getByText(/will not power on/)).toBeInTheDocument()
    expect(screen.getByText('Assignment')).toBeInTheDocument()
  })

  it('labels the type from the server vocabulary when it is given one', () => {
    // The label must come from the API's `meta.types`, so a type added
    // server-side is named by the server rather than by a table here.
    renderRow(
      { type: 'announcement' },
      { typeOptions: [{ value: 'announcement', label: 'Notice' }] },
    )

    expect(screen.getByText('Notice')).toBeInTheDocument()
  })

  it('renders an unknown future type instead of failing on it', () => {
    // D5's announcements arrive in a later work package, and WP-2.4b adds more
    // topics after that. A row that switched exhaustively would throw here.
    renderRow({ type: 'procurement', topic: 'procurement.approved' })

    expect(screen.getByText('Procurement')).toBeInTheDocument()
  })

  it('survives a notification with no message', () => {
    renderRow({ message: null })

    expect(screen.getByText('Ticket TKT-000123 assigned to you')).toBeInTheDocument()
    expect(screen.queryByText(/will not power on/)).not.toBeInTheDocument()
  })

  it('gives the timestamp both readings — relative in text, absolute in the title', () => {
    renderRow()

    const time = screen.getByText(/ago|just now|\d/, { selector: 'time' })
    expect(time).toHaveAttribute('dateTime', '2026-09-05T08:00:00+00:00')
    expect(time.getAttribute('title')).toBeTruthy()
  })
})

describe('unread state', () => {
  it('says "Unread" in words, not only in colour', () => {
    renderRow({ is_read: false })

    expect(screen.getByText(/^Unread\./)).toBeInTheDocument()
  })

  it('says "Read" once it has been read', () => {
    renderRow({ is_read: true, read_at: '2026-09-05T09:00:00+00:00' })

    expect(screen.getByText(/^Read\./)).toBeInTheDocument()
  })

  it('offers "Mark as read" while unread and "Mark as unread" afterwards', async () => {
    const user = userEvent.setup()
    const { onMarkRead } = renderRow({ is_read: false })

    await user.click(screen.getByRole('button', { name: /mark as read/i }))
    expect(onMarkRead).toHaveBeenCalledWith('n-1')
  })

  it('restores a read notification to unread', async () => {
    const user = userEvent.setup()
    const { onMarkUnread } = renderRow({ is_read: true, read_at: '2026-09-05T09:00:00+00:00' })

    await user.click(screen.getByRole('button', { name: /mark as unread/i }))
    expect(onMarkUnread).toHaveBeenCalledWith('n-1')
  })
})

describe('the destination', () => {
  it('links to the action_url the server built', () => {
    renderRow()

    expect(screen.getByRole('link', { name: /open/i })).toHaveAttribute(
      'href',
      '/app/tickets/2f1c8b0e-0000-4000-8000-000000000001',
    )
  })

  it('renders no link when the notification has no destination', () => {
    // `account.locked` is the live case: its destination would be the sign-in
    // page the user was just refused.
    renderRow({ action_url: null, type: 'system', topic: 'account.locked' })

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  it('refuses an external destination rather than following it', () => {
    renderRow({ action_url: 'https://elsewhere.example/app/tickets/1' })

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  it('refuses a protocol-relative destination', () => {
    renderRow({ action_url: '//elsewhere.example/app/x' })

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  it('refuses a path outside the authenticated application', () => {
    renderRow({ action_url: '/sign-in' })

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  it('marks the notification read on the way out', async () => {
    const user = userEvent.setup()
    const { onMarkRead } = renderRow({ is_read: false })

    await user.click(screen.getByRole('link', { name: /open/i }))

    expect(onMarkRead).toHaveBeenCalledWith('n-1')
  })

  it('does not re-mark a notification that was already read', async () => {
    const user = userEvent.setup()
    const { onMarkRead } = renderRow({ is_read: true, read_at: '2026-09-05T09:00:00+00:00' })

    await user.click(screen.getByRole('link', { name: /open/i }))

    expect(onMarkRead).not.toHaveBeenCalled()
  })
})

describe('the compact variant', () => {
  it('drops the read control but keeps the state and the destination', () => {
    renderRow({}, { compact: true })

    expect(screen.queryByRole('button', { name: /mark as/i })).not.toBeInTheDocument()
    expect(screen.getByText(/^Unread\./)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /open/i })).toBeInTheDocument()
  })
})
