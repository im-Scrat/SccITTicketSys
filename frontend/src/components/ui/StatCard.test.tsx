import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { StatCard } from './StatCard'

const renderCard = (ui: React.ReactElement) => render(<MemoryRouter>{ui}</MemoryRouter>)

describe('StatCard', () => {
  it('becomes a real link named "label: value" when given a destination', () => {
    renderCard(<StatCard label="Overdue" value="2" tone="danger" to="/app/tickets?view=overdue" />)

    const link = screen.getByRole('link', { name: 'Overdue: 2' })
    expect(link).toHaveAttribute('href', '/app/tickets?view=overdue')
  })

  it('is a link even at zero — "nothing overdue" is something you can open', () => {
    renderCard(<StatCard label="Overdue" value="0" to="/app/tickets?view=overdue" />)

    expect(screen.getByRole('link', { name: 'Overdue: 0' })).toBeInTheDocument()
  })

  it('still renders a filter button when given onClick', async () => {
    const onClick = vi.fn()
    renderCard(<StatCard label="Unassigned" value={4} active onClick={onClick} />)

    const button = screen.getByRole('button', { name: /Unassigned/ })
    expect(button).toHaveAttribute('aria-pressed', 'true')
    await userEvent.click(button)
    expect(onClick).toHaveBeenCalledOnce()
  })

  it('is plain text with neither', () => {
    renderCard(<StatCard label="Automatic closure" value="14 days" />)

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.queryByRole('button')).toBeNull()
    expect(screen.getByText('14 days')).toBeInTheDocument()
  })
})
