import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { NotificationBell } from './NotificationBell'

/**
 * The header badge (SRS FR-NOT-001, NFR-ACC-004/009).
 *
 * Every assertion here is about the **accessible name**, not about the pixels,
 * because the badge is the one piece of state in this feature that is purely
 * visual — and a count a screen reader cannot hear is a count that does not
 * exist for the person who most needs the summary.
 */
function renderBell(unreadCount: number, open = false) {
  const onToggle = vi.fn()

  render(
    <NotificationBell
      unreadCount={unreadCount}
      open={open}
      onToggle={onToggle}
      panelId="panel-1"
    />,
  )

  return { onToggle }
}

describe('the badge', () => {
  it('is absent at zero, and the name says only "Notifications"', () => {
    renderBell(0)

    const button = screen.getByRole('button', { name: 'Notifications' })
    expect(button).toBeInTheDocument()
    // An indicator that is always lit means nothing.
    expect(button.textContent).toBe('')
  })

  it('states the count in the accessible name, not only in the badge', () => {
    renderBell(3)

    expect(screen.getByRole('button', { name: 'Notifications, 3 unread' })).toBeInTheDocument()
    expect(screen.getByRole('button').textContent).toBe('3')
  })

  it('caps the visible badge at 99+ so the header cannot reflow', () => {
    renderBell(1284)

    const button = screen.getByRole('button')
    expect(button.textContent).toBe('99+')
    // The real number still reaches assistive technology.
    expect(button).toHaveAccessibleName('Notifications, 1284 unread')
  })

  it('shows 99 without the cap, and 100 with it', () => {
    const { unmount } = render(
      <NotificationBell unreadCount={99} open={false} onToggle={vi.fn()} panelId="p" />,
    )
    expect(screen.getByRole('button').textContent).toBe('99')
    unmount()

    render(<NotificationBell unreadCount={100} open={false} onToggle={vi.fn()} panelId="p" />)
    expect(screen.getByRole('button').textContent).toBe('99+')
  })

  it('reverts to the plain name when the last notification is read', () => {
    const { rerender } = render(
      <NotificationBell unreadCount={2} open={false} onToggle={vi.fn()} panelId="p" />,
    )
    expect(screen.getByRole('button')).toHaveAccessibleName('Notifications, 2 unread')

    rerender(<NotificationBell unreadCount={0} open={false} onToggle={vi.fn()} panelId="p" />)
    expect(screen.getByRole('button')).toHaveAccessibleName('Notifications')
  })

  it('never renders a negative count', () => {
    renderBell(-1)

    expect(screen.getByRole('button')).toHaveAccessibleName('Notifications')
  })
})

describe('the control', () => {
  it('declares what it opens, and whether it is open', () => {
    const { unmount } = render(
      <NotificationBell unreadCount={0} open={false} onToggle={vi.fn()} panelId="panel-1" />,
    )
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false')
    expect(screen.getByRole('button')).toHaveAttribute('aria-controls', 'panel-1')
    unmount()

    render(<NotificationBell unreadCount={0} open onToggle={vi.fn()} panelId="panel-1" />)
    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'true')
  })

  it('toggles on Enter and on Space, as a button must', async () => {
    const user = userEvent.setup()
    const { onToggle } = renderBell(1)

    screen.getByRole('button').focus()
    await user.keyboard('{Enter}')
    await user.keyboard(' ')

    expect(onToggle).toHaveBeenCalledTimes(2)
  })
})
