import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import type { TechnicianSummary } from '../types'
import { TechnicianSummaryTable } from './TechnicianSummaryTable'

const ANA = {
  id: 'tech-ana',
  name: 'Ana Cruz',
  account_status: 'active',
  recorded: {
    active_assignments: 3,
    completed_assignments: 10,
    completed_maintenance: 2,
    last_activity_at: null,
  },
  calculated: {
    median_minutes_to_accept: 12,
    median_minutes_to_start: 30,
    median_minutes_to_complete: 240,
    sla_breached: 1,
  },
} as unknown as TechnicianSummary

function Where() {
  return <p data-testid="where">{useLocation().pathname}</p>
}

function renderTable() {
  render(
    <MemoryRouter initialEntries={['/app/technicians']}>
      <Routes>
        <Route
          path="/app/technicians"
          element={<TechnicianSummaryTable technicians={[ANA]} caption="Technicians" />}
        />
        <Route path="/app/technicians/:id" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('TechnicianSummaryTable', () => {
  it('opens the technician from anywhere on the row, not only the name', async () => {
    renderTable()

    // A figure cell, far from the name.
    await userEvent.click(screen.getByText('10'))

    expect(screen.getByTestId('where')).toHaveTextContent('/app/technicians/tech-ana')
  })

  it('keeps the name as a real link for the keyboard and new tabs', () => {
    renderTable()

    expect(screen.getByRole('link', { name: 'Ana Cruz' })).toHaveAttribute(
      'href',
      '/app/technicians/tech-ana',
    )
  })
})
