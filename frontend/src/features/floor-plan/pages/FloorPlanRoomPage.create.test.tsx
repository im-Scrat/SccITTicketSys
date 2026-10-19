import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useViewportStore } from '../stores/useViewportStore'
import { pc, roomPlan, unplacedPc } from '../test/fixtures'
import type { RoomPlan } from '../types'
import FloorPlanRoomPage from './FloorPlanRoomPage'

/**
 * Creating a floor plan, and the map card that replaced the bare canvas
 * (fix pass 2). An Administrator opened a room with no layout and found a
 * dead end: the server could create and activate a layout, the client never
 * asked it to. These tests pin the way out and what the finished map shows.
 */

const get = vi.fn()
const post = vi.fn()
const patch = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    patch: (...args: unknown[]) => patch(...args),
  },
}))

vi.mock('@/services/echo', () => {
  function stubChannel(): { listen: () => unknown } {
    const channel = { listen: () => channel }
    return channel
  }
  return { getEcho: () => Promise.resolve({ private: stubChannel, leave: () => undefined }) }
})

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/floor-plan/rooms/room-uuid-1']}>
        <Routes>
          <Route path="/app/floor-plan/rooms/:id" element={<FloorPlanRoomPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const serve = (plan: RoomPlan) => get.mockResolvedValue({ data: { data: plan } })

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  patch.mockReset()
  useViewportStore.setState({ zoom: 1, x: 0, y: 0, content: null })
  Element.prototype.setPointerCapture = vi.fn()
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

describe('a room with no layout', () => {
  it('offers an editor the create form, and creates then activates the version', async () => {
    const empty = roomPlan({
      layout: null,
      pcs: [],
      unplaced_count: 3,
      editor: { can_edit: true, snap_to_grid: true },
    })
    const live = roomPlan({
      layout: { version: 3, width: 1200, height: 800, grid_size: 20 },
      pcs: [],
      unplaced: [unplacedPc(1)],
      unplaced_count: 1,
      editor: { can_edit: true, snap_to_grid: true },
    })
    get.mockResolvedValueOnce({ data: { data: empty } }).mockResolvedValue({ data: { data: live } })
    post
      .mockResolvedValueOnce({
        data: { data: { version: 3, width: 1200, height: 800, grid_size: 20, is_active: false } },
      })
      .mockResolvedValueOnce({
        data: { data: { version: 3, width: 1200, height: 800, grid_size: 20, is_active: true } },
      })

    renderPage()

    expect(await screen.findByText('Create this room’s floor plan')).toBeInTheDocument()
    expect(screen.getByText(/3 units are in this room/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Create floor plan' }))

    await waitFor(() => expect(post).toHaveBeenCalledTimes(2))
    expect(post.mock.calls[0]).toEqual([
      '/admin/floor-plan/rooms/room-uuid-1/layouts',
      { width: 1200, height: 800, grid_size: undefined },
    ])
    expect(post.mock.calls[1][0]).toBe('/admin/floor-plan/rooms/room-uuid-1/layouts/3/activate')

    // The plan is re-read and the map takes over from the form.
    expect(await screen.findByTestId('floor-plan-svg')).toBeInTheDocument()
    expect(screen.queryByText('Create this room’s floor plan')).not.toBeInTheDocument()
  })

  it("refuses a size outside the server's limits before sending anything", async () => {
    serve(
      roomPlan({
        layout: null,
        pcs: [],
        unplaced_count: 0,
        editor: { can_edit: true, snap_to_grid: true },
      }),
    )
    renderPage()

    const width = await screen.findByLabelText(/^Width/)
    await userEvent.clear(width)
    await userEvent.type(width, '0')
    await userEvent.click(screen.getByRole('button', { name: 'Create floor plan' }))

    expect(await screen.findByText(/Enter a whole number from 1 to 100000/)).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })

  it('shows a viewer the empty state only — no form, no requests to change anything', async () => {
    serve(roomPlan({ layout: null, pcs: [], unplaced_count: 3 }))
    renderPage()

    expect(await screen.findByText('No floor plan for this room yet')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Create floor plan' })).not.toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })
})

describe('the map card', () => {
  it('counts units and alerts in the header, and lists the legend in the footer', async () => {
    serve(
      roomPlan({
        pcs: [
          pc(1, 'available', { open_ticket: { id: 't-1', reference: 'TKT-2026-00042' } }),
          pc(2, 'available'),
          pc(3, 'needs_fix'),
        ],
      }),
    )
    renderPage()

    expect(await screen.findByTestId('floor-plan-summary')).toHaveTextContent('3 units · 2 alerts')
    expect(screen.getByRole('region', { name: 'Legend' })).toHaveTextContent('Needs Fix')
  })

  it('shows the unit code, status and open ticket when a unit is hovered', async () => {
    serve(
      roomPlan({
        pcs: [pc(1, 'pending_repair', { open_ticket: { id: 't-1', reference: 'TKT-2026-00042' } })],
      }),
    )
    renderPage()

    const node = await screen.findByTestId('floor-plan-node')
    expect(screen.queryByTestId('floor-plan-tooltip')).not.toBeInTheDocument()

    fireEvent.pointerEnter(node)

    const tip = screen.getByTestId('floor-plan-tooltip')
    expect(tip).toHaveTextContent('LAB-1')
    expect(tip).toHaveTextContent('Pending Repair · Ticket TKT-2026-00042')

    fireEvent.pointerLeave(node)
    expect(screen.queryByTestId('floor-plan-tooltip')).not.toBeInTheDocument()
  })
})

describe('arranging unplaced units', () => {
  it('places each unplaced unit once, on a distinct grid spot, through the normal write', async () => {
    serve(
      roomPlan({
        pcs: [],
        unplaced: [unplacedPc(1), unplacedPc(2), unplacedPc(3)],
        unplaced_count: 3,
        editor: { can_edit: true, snap_to_grid: true },
      }),
    )
    patch.mockImplementation((_url: string, body: { x: number; y: number }) =>
      Promise.resolve({
        data: {
          data: pc(9, 'available', { id: `placed-${body.x}-${body.y}`, x: body.x, y: body.y }),
        },
      }),
    )
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /Arrange 3 unplaced units/ }))

    await waitFor(() => expect(patch).toHaveBeenCalledTimes(3))
    const spots = patch.mock.calls.map(([, body]) => `${body.x},${body.y}`)
    expect(new Set(spots).size).toBe(3)
    for (const [url] of patch.mock.calls) {
      expect(url).toMatch(
        /^\/admin\/floor-plan\/rooms\/room-uuid-1\/layouts\/1\/positions\/unplaced-uuid-\d$/,
      )
    }
  })
})
