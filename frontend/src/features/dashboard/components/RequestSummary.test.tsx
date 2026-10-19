import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { KpiItem, KpiWidget } from '../types'
import { RequestSummaryHeader, RequestSummaryList } from './RequestSummary'

const ITEMS: KpiItem[] = [
  { key: 'open', label: 'Open', value: 5 },
  { key: 'awaiting_confirmation', label: 'Awaiting your confirmation', value: 1, tone: 'warning' },
  { key: 'resolved_recently', label: 'Resolved (30 days)', value: 2 },
]

function renderSummary(items: KpiItem[] = ITEMS, href: string | undefined = '/app/tickets/mine') {
  const widget: KpiWidget = { key: 'my-requests', type: 'kpi', title: 'My requests', href, items }

  return render(
    <MemoryRouter>
      {/* The dashboard seats these in separate grid cells; together they are the panel. */}
      <RequestSummaryHeader widget={widget} />
      <RequestSummaryList widget={widget} />
    </MemoryRouter>,
  )
}

describe('RequestSummary', () => {
  it('turns each figure into a link to the list of exactly those tickets', () => {
    renderSummary()

    expect(screen.getByRole('link', { name: 'Open: 5' })).toHaveAttribute(
      'href',
      '/app/tickets/mine?view=open',
    )
    expect(screen.getByRole('link', { name: 'Awaiting your confirmation: 1' })).toHaveAttribute(
      'href',
      '/app/tickets/mine?view=awaiting',
    )
    expect(screen.getByRole('link', { name: 'Resolved (30 days): 2' })).toHaveAttribute(
      'href',
      '/app/tickets/mine?view=resolved',
    )
  })

  it('shows each count as text and says in a line what it counts', () => {
    renderSummary()

    const open = screen.getByRole('link', { name: 'Open: 5' })
    expect(within(open).getByText('5')).toBeInTheDocument()
    expect(open).toHaveAccessibleDescription('Not closed yet')

    expect(
      screen.getByRole('link', { name: /^Awaiting your confirmation/ }),
    ).toHaveAccessibleDescription('Check the fix and confirm it')
    expect(screen.getByRole('link', { name: /^Resolved \(30 days\)/ })).toHaveAccessibleDescription(
      'Fixed in the last 30 days',
    )
  })

  it('keeps the long label as text of the row, so it can wrap instead of being cut', () => {
    renderSummary()

    const awaiting = screen.getByRole('link', { name: /^Awaiting your confirmation/ })
    expect(within(awaiting).getByText('Awaiting your confirmation')).toBeVisible()
  })

  it('tints a row only when the server marks it', () => {
    renderSummary()

    expect(screen.getByRole('link', { name: /^Awaiting your confirmation/ })).toHaveClass(
      'bg-warning-subtle',
    )
    expect(screen.getByRole('link', { name: 'Open: 5' })).not.toHaveClass('bg-warning-subtle')
    expect(screen.getByRole('link', { name: /^Resolved/ })).not.toHaveClass('bg-warning-subtle')
  })

  it('leaves the awaiting row untinted when nothing is waiting', () => {
    // The server sends no tone for a zero count; the client does not invent one.
    renderSummary(
      ITEMS.map((item) =>
        item.key === 'awaiting_confirmation' ? { ...item, value: 0, tone: null } : item,
      ),
    )

    expect(screen.getByRole('link', { name: 'Awaiting your confirmation: 0' })).not.toHaveClass(
      'bg-warning-subtle',
    )
  })

  it('renders a figure it does not know as a plain row, not a guessed link', () => {
    renderSummary([
      ...ITEMS,
      { key: 'total', label: 'All time', value: 9 },
      // An inherited property name must not be mistaken for a known figure.
      { key: 'constructor', label: 'Inherited', value: 3 },
    ])

    expect(screen.getByText('All time')).toBeInTheDocument()
    expect(screen.getByText('9')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /All time/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Inherited/ })).not.toBeInTheDocument()
  })

  it('honours an href the server sends over the one it would build', () => {
    renderSummary([{ key: 'open', label: 'Open', value: 5, href: '/app/tickets/mine?status=open' }])

    expect(screen.getByRole('link', { name: 'Open: 5' })).toHaveAttribute(
      'href',
      '/app/tickets/mine?status=open',
    )
  })

  it('is a dashboard panel: the shared title, and a way in to the whole list', () => {
    renderSummary()

    expect(screen.getByRole('heading', { level: 2, name: 'My requests' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'View all tickets' })).toHaveAttribute(
      'href',
      '/app/tickets/mine',
    )
  })

  it('falls back to My tickets for "View all" when the widget carries no href', () => {
    renderSummary(ITEMS, undefined)

    expect(screen.getByRole('link', { name: 'View all tickets' })).toHaveAttribute(
      'href',
      '/app/tickets/mine',
    )
  })

  it('renders nothing for a widget with no figures', () => {
    const { container } = renderSummary([])

    expect(container).toBeEmptyDOMElement()
  })
})
