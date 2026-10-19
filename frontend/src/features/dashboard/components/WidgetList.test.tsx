import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, useLocation } from 'react-router-dom'
import type { ListRow } from '../types'
import { WidgetList } from './WidgetList'

const NOW = new Date('2026-10-17T10:00:00Z')

function Where() {
  const location = useLocation()
  return <output data-testid="where">{location.pathname}</output>
}

const renderList = (rows: ListRow[]) =>
  render(
    <MemoryRouter initialEntries={['/app']}>
      <WidgetList columns={['Ticket', 'Priority', 'Due']} rows={rows} />
      <Where />
    </MemoryRouter>,
  )

const ROW: ListRow = {
  id: 'abc',
  cells: ['TKT-1 · Projector dead', 'High', '2026-10-17T13:00:00Z'],
  timestamp_cell: 2,
  href: '/app/tickets/assigned/abc',
}

describe('WidgetList', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('makes the first cell of a row with an href a real link', () => {
    renderList([ROW])

    expect(screen.getByRole('link', { name: 'TKT-1 · Projector dead' })).toHaveAttribute(
      'href',
      '/app/tickets/assigned/abc',
    )
    // One link per row, not one per cell.
    expect(screen.getAllByRole('link')).toHaveLength(1)
  })

  it('opens the record when the rest of the row is clicked', async () => {
    renderList([ROW])

    await userEvent.click(screen.getByText('High'))

    expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/assigned/abc')
  })

  it('leaves a click on the link to the link', async () => {
    renderList([ROW])

    await userEvent.click(screen.getByRole('link', { name: /Projector dead/ }))

    // Navigated once, to the link's own href — the row handler stood aside.
    expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/assigned/abc')
  })

  it('shows a row with no href as plain text', async () => {
    renderList([{ ...ROW, href: undefined }])

    expect(screen.queryByRole('link')).toBeNull()
    await userEvent.click(screen.getByText('High'))
    expect(screen.getByTestId('where')).toHaveTextContent('/app')
  })

  it('renders a future time as "in …", inside a <time> carrying the ISO value', () => {
    renderList([ROW])

    const time = screen.getByText('in 3h')
    expect(time.tagName).toBe('TIME')
    expect(time).toHaveAttribute('datetime', '2026-10-17T13:00:00Z')
  })

  it('shows the empty label when there are no rows', () => {
    render(
      <MemoryRouter>
        <WidgetList columns={['A']} rows={[]} emptyLabel="Nothing is assigned to you." />
      </MemoryRouter>,
    )

    expect(screen.getByText('Nothing is assigned to you.')).toBeInTheDocument()
  })
})
