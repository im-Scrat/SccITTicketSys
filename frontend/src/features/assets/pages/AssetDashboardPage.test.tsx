import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { AssetDashboard } from '../types'
import AssetDashboardPage from './AssetDashboardPage'

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ hasPermission: () => false }),
}))

const DATA: AssetDashboard = {
  summary: {
    total: 9,
    new: 0,
    available: 3,
    assigned: 1,
    in_service: 2,
    maintenance: 1,
    out_of_service: 1,
    in_transit: 0,
    retired: 0,
    disposed: 0,
    archived: 0,
    unassigned_location: 4,
  },
  by_status: [{ key: 'in_stock', label: 'Available', count: 3 }],
  by_building: [
    { key: 'b-1', label: 'Main Hall', count: 5 },
    { key: null, label: 'Unassigned', count: 4 },
  ],
  by_room: [{ key: 'r-1', label: 'Lab 1', count: 5 }],
  by_category: [{ key: 'printer', label: 'Printer', count: 2 }],
  warranty_expiring: { count: 0, days: 90, items: [] },
  recently_added: [],
  recently_updated: [],
  pc_units: { by_status: [] },
  filters: { statuses: [], conditions: [], categories: [] },
  generated_at: '2026-10-20T00:00:00Z',
}

vi.mock('../hooks/queries', () => ({
  useAssetDashboard: () => ({ data: DATA, isLoading: false, isError: false }),
}))

function Where() {
  const location = useLocation()
  return <p data-testid="where">{location.pathname + location.search}</p>
}

function renderPage() {
  render(
    <MemoryRouter initialEntries={['/app/assets']}>
      <Routes>
        <Route path="/app/assets" element={<AssetDashboardPage />} />
        <Route path="/app/assets/list" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  )
}

/** Owner request 2026-10-20: every panel on /app/assets opens the list behind it. */
describe('AssetDashboardPage — every panel is a way in', () => {
  it('opens the assets with no room from "Not placed in a room"', async () => {
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: /not placed in a room/i }))

    expect(screen.getByTestId('where')).toHaveTextContent('/app/assets/list?unassigned=1')
  })

  it('opens the assets with no room from the "Unassigned" building row', async () => {
    renderPage()

    const byBuilding = screen.getByRole('table', { name: /by building/i })
    await userEvent.click(within(byBuilding).getByRole('button', { name: 'Unassigned' }))

    expect(screen.getByTestId('where')).toHaveTextContent('/app/assets/list?unassigned=1')
  })

  it.each([
    ['By status', '/app/assets/list?sort=status'],
    ['By building', '/app/assets/list?sort=building'],
    ['By room', '/app/assets/list?sort=room'],
    ['By category', '/app/assets/list?sort=category'],
    ['PC status', '/app/assets/list?tab=pc-units'],
    ['Warranty expiring soon', '/app/assets/list?warranty_expiring=90&sort=warranty_expiration'],
    ['Recently added', '/app/assets/list?sort=created_at&direction=desc'],
    ['Recently updated', '/app/assets/list?sort=updated_at&direction=desc'],
  ])('gives the "%s" panel a "View list" link to %s', async (panel, target) => {
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: `View list: ${panel}` }))

    expect(screen.getByTestId('where')).toHaveTextContent(target)
  })
})
