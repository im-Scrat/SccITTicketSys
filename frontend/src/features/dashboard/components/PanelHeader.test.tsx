import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { PanelHeader } from './PanelHeader'
import { WidgetCard } from './WidgetCard'

const renderInRouter = (ui: React.ReactElement) => render(<MemoryRouter>{ui}</MemoryRouter>)

describe('PanelHeader', () => {
  it('is a level-2 title with its description and a link into the full view', () => {
    renderInRouter(
      <PanelHeader
        title="Reported problems"
        description="Check here before reporting."
        link={{ to: '/app/tickets', label: 'Search and filter all' }}
      />,
    )

    expect(screen.getByRole('heading', { level: 2, name: 'Reported problems' })).toBeInTheDocument()
    expect(screen.getByText('Check here before reporting.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Search and filter all' })).toHaveAttribute(
      'href',
      '/app/tickets',
    )
  })

  it('puts the link on the title row, ahead of the description, so two headers stay level', () => {
    renderInRouter(
      <PanelHeader
        title="My requests"
        description="Select a figure."
        link={{ to: '/app/tickets/mine', label: 'View all tickets' }}
      />,
    )

    const title = screen.getByRole('heading', { name: 'My requests' })
    const link = screen.getByRole('link', { name: 'View all tickets' })
    const description = screen.getByText('Select a figure.')

    // One row holds title and link; the description is outside it.
    expect(title.parentElement).toBe(link.parentElement)
    expect(title.parentElement).not.toContainElement(description)
  })

  it('names itself for the region below it when given an id', () => {
    renderInRouter(<PanelHeader id="feed-heading" title="Reported problems" />)

    expect(screen.getByRole('heading', { name: 'Reported problems' })).toHaveAttribute(
      'id',
      'feed-heading',
    )
  })

  it('renders no link or description when none is given', () => {
    renderInRouter(<PanelHeader title="Announcements" />)

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})

describe('WidgetCard variants', () => {
  it('keeps the dashboard widget as the default, with its title inside the card', () => {
    const { container } = renderInRouter(
      <WidgetCard title="Open tickets" href="/app/tickets">
        body
      </WidgetCard>,
    )

    expect(screen.getByRole('heading', { level: 2, name: 'Open tickets' })).toBeInTheDocument()
    // The widget frame, not the page-level one.
    expect(container.querySelector('.rounded-md.border')).not.toBeNull()
    expect(container.querySelector('.border-2')).toBeNull()
    expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute('href', '/app/tickets')
  })

  it('"section" is a named region: the page-level heading above a feed-card frame', () => {
    const { container } = renderInRouter(
      <WidgetCard title="Announcements" variant="section">
        body
      </WidgetCard>,
    )

    expect(screen.getByRole('region', { name: 'Announcements' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'Announcements' })).toBeInTheDocument()
    // The same frame a ticket card uses.
    expect(container.querySelector('.rounded-lg.border-2')).not.toBeNull()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})
