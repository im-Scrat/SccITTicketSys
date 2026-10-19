import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { UnreadAnnouncer } from './UnreadAnnouncer'

/**
 * The live region (NFR-ACC-004, WCAG 4.1.3).
 *
 * The risk this component exists to avoid is a *regression in the other
 * direction*: a live region that fires on every poll would talk over a screen
 * reader user once a minute, forever, on every authenticated screen. That is
 * worse than having no announcement at all, so the restraint below is the
 * feature.
 */
function region() {
  return screen.getByRole('status')
}

describe('what is announced', () => {
  it('says nothing for the first value it sees', () => {
    // Signing in is not an event; the count is simply the starting state.
    render(<UnreadAnnouncer unreadCount={4} />)

    expect(region()).toHaveTextContent('')
  })

  it('announces an increase, politely', () => {
    const { rerender } = render(<UnreadAnnouncer unreadCount={1} minIntervalMs={0} />)
    rerender(<UnreadAnnouncer unreadCount={3} minIntervalMs={0} />)

    expect(region()).toHaveTextContent('3 unread notifications')
    expect(region()).toHaveAttribute('aria-live', 'polite')
  })

  it('uses the singular for one', () => {
    const { rerender } = render(<UnreadAnnouncer unreadCount={0} minIntervalMs={0} />)
    rerender(<UnreadAnnouncer unreadCount={1} minIntervalMs={0} />)

    expect(region()).toHaveTextContent('1 unread notification')
  })

  it('is never assertive — it must not interrupt what is being read', () => {
    render(<UnreadAnnouncer unreadCount={2} />)

    expect(region()).not.toHaveAttribute('aria-live', 'assertive')
  })
})

describe('what is not announced', () => {
  it('stays silent when the count is unchanged by a poll', () => {
    const { rerender } = render(<UnreadAnnouncer unreadCount={2} minIntervalMs={0} />)
    rerender(<UnreadAnnouncer unreadCount={2} minIntervalMs={0} />)

    expect(region()).toHaveTextContent('')
  })

  it('stays silent when the user marks something read', () => {
    // Narrating the user's own clicking back at them is noise, not information.
    const { rerender } = render(<UnreadAnnouncer unreadCount={5} minIntervalMs={0} />)
    rerender(<UnreadAnnouncer unreadCount={4} minIntervalMs={0} />)

    expect(region()).toHaveTextContent('')
  })

  it('debounces a burst into a single announcement', () => {
    const { rerender } = render(<UnreadAnnouncer unreadCount={1} minIntervalMs={60_000} />)
    rerender(<UnreadAnnouncer unreadCount={2} minIntervalMs={60_000} />)
    expect(region()).toHaveTextContent('2 unread notifications')

    // The second increase arrives inside the interval and must not queue a
    // second utterance on top of the first.
    rerender(<UnreadAnnouncer unreadCount={9} minIntervalMs={60_000} />)
    expect(region()).toHaveTextContent('2 unread notifications')
  })

  it('says nothing at all while the count is still loading', () => {
    render(<UnreadAnnouncer unreadCount={undefined} />)

    expect(region()).toHaveTextContent('')
  })
})
