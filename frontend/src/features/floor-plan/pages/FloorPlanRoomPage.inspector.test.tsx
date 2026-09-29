import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useViewportStore } from '../stores/useViewportStore'
import { pc, roomPlan } from '../test/fixtures'
import type { RoomPlan } from '../types'
import FloorPlanRoomPage from './FloorPlanRoomPage'

/**
 * WP-G — the maintenance-history inspector as wired into the room page: the
 * table's "Details" button opens `PcInspectorPanel`, which fetches the unit
 * and its history through the API mocked here (the panel's own content is
 * covered in PcInspectorPanel.test.tsx; this file proves the wiring, not the
 * content). Selecting a unit on the map deliberately does **not** also open
 * it — see the design note on `PcUnitList`.
 */

const get = vi.fn()

vi.mock('@/services/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

vi.mock('@/services/echo', () => {
  function stubChannel(): { listen: () => unknown } {
    const channel = { listen: () => channel }
    return channel
  }
  return { getEcho: () => Promise.resolve({ private: stubChannel, leave: () => undefined }) }
})

function renderPage(plan: RoomPlan) {
  get.mockImplementation((url: string) => {
    if (url === '/admin/floor-plan/rooms/room-uuid-1')
      return Promise.resolve({ data: { data: plan } })
    if (url.endsWith('/maintenance')) return Promise.resolve({ data: { data: [] } })
    // Any /admin/pc-units/{id} lookup for the inspector.
    return Promise.resolve({
      data: {
        data: {
          id: 'pc-uuid-1',
          unit_code: 'LAB-1',
          pc_name: 'PC-01',
          asset_tag: null,
          hostname: null,
          serial_number: null,
          brand: null,
          model: null,
          status: 'online',
          status_label: 'Online',
          condition: 'working',
          condition_label: 'Working',
          room: null,
          building: null,
          warranty_expiration: null,
          warranty_days_remaining: null,
          qr_identifier: null,
          created_at: null,
          updated_at: null,
          archived: false,
          notes: null,
          network: { ip_address: null, mac_address: null },
          purchase: { date: null },
          warranty: {
            expiration: null,
            days_remaining: null,
            under_warranty: false,
            purchase_date: null,
          },
          floor: null,
          specification: null,
          active_tickets: [],
          created_by: null,
          updated_by: null,
          archived_at: null,
        },
      },
    })
  })

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/floor-plan/rooms/room-uuid-1']}>
        <Routes>
          <Route path="/app/floor-plan/rooms/:id" element={<FloorPlanRoomPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  get.mockReset()
  useViewportStore.setState({ zoom: 1, x: 0, y: 0, content: null })
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({
    x: 0,
    y: 0,
    left: 0,
    top: 0,
    right: 1000,
    bottom: 600,
    width: 1000,
    height: 600,
    toJSON: () => ({}),
  })
})

describe('FloorPlanRoomPage — maintenance inspector (WP-G)', () => {
  it('opens the inspector for the unit chosen from the table', async () => {
    renderPage(roomPlan({ pcs: [pc(1, 'online', { x: 100, y: 120 })] }))

    await screen.findByRole('heading', { name: 'Computer Lab 2' })
    fireEvent.click(screen.getByRole('button', { name: 'View details for PC-01' }))

    expect(await screen.findByRole('heading', { name: 'PC-01' })).toBeInTheDocument()
    expect(get).toHaveBeenCalledWith('/admin/pc-units/pc-uuid-1')
    expect(get).toHaveBeenCalledWith('/admin/pc-units/pc-uuid-1/maintenance')
  })

  it('closes when asked, and stops showing that unit', async () => {
    renderPage(roomPlan({ pcs: [pc(1, 'online', { x: 100, y: 120 })] }))

    await screen.findByRole('heading', { name: 'Computer Lab 2' })
    fireEvent.click(screen.getByRole('button', { name: 'View details for PC-01' }))
    await screen.findByRole('heading', { name: 'PC-01' })

    fireEvent.click(screen.getByRole('button', { name: 'Close unit details' }))
    expect(screen.queryByRole('heading', { name: 'PC-01' })).not.toBeInTheDocument()
  })

  it('does not open the inspector merely from arming keyboard-move on the map', async () => {
    // Regression guard: this used to also call the inspector open, which fired
    // two extra requests and popped a full history panel on every Enter press
    // that starts a keyboard move — surprising mid-gesture, and exactly the
    // coupling FloorPlanRoomPage.editing.test.tsx's own keyboard-move
    // assertions do not expect from `onSelect`.
    renderPage(
      roomPlan({
        pcs: [pc(1, 'online', { x: 120, y: 80 })],
        editor: { can_edit: true, snap_to_grid: true },
      }),
    )

    await screen.findByRole('heading', { name: 'Computer Lab 2' })
    const node = screen.getByRole('button', { name: /^PC-01/ })
    fireEvent.keyDown(node, { key: 'Enter' })

    expect(screen.queryByRole('heading', { name: 'PC-01' })).not.toBeInTheDocument()
    expect(get).not.toHaveBeenCalledWith('/admin/pc-units/pc-uuid-1')
  })
})
