import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { AxiosError } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { roomPlan } from '../test/fixtures'
import { useViewportStore } from '../stores/useViewportStore'
import FloorPlanRoomPage from './FloorPlanRoomPage'

const get = vi.fn()

vi.mock('@/services/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

// WP-E: the page subscribes to the room's Reverb channel on mount. Stubbed so
// that never resolves into a real socket (or a call through the mocked `api`
// above, which would double-count `get`) — realtime reconciliation itself is
// covered in useFloorPlanChannel.test.tsx.
vi.mock('@/services/echo', () => {
  function stubChannel(): { listen: () => unknown } {
    const channel = { listen: () => channel }
    return channel
  }
  return { getEcho: () => Promise.resolve({ private: stubChannel, leave: () => undefined }) }
})

function renderPage() {
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

function refusal(status: number) {
  return new AxiosError('refused', String(status), undefined, undefined, {
    status,
    data: {},
    statusText: '',
    headers: {},
    config: {} as never,
  })
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

describe('FloorPlanRoomPage', () => {
  it('asks the server for the room named in the URL', async () => {
    get.mockResolvedValue({ data: { data: roomPlan() } })
    renderPage()

    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    expect(get).toHaveBeenCalledWith('/admin/floor-plan/rooms/room-uuid-1')
  })

  it('renders the map, legend, controls and text alternative from the payload', async () => {
    get.mockResolvedValue({ data: { data: roomPlan({ unplaced_count: 2 }) } })
    renderPage()

    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    expect(screen.getByTestId('floor-plan-svg')).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Floor plan view controls' })).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Legend' })).toBeInTheDocument()
    expect(
      screen.getByRole('table', { name: /Units placed in Computer Lab 2/ }),
    ).toBeInTheDocument()
    expect(screen.getByText(/2 units in this room are not placed/)).toBeInTheDocument()
    expect(screen.getByText(/Main Building · Ground floor/)).toBeInTheDocument()
  })

  it('shows the Forbidden page, and no plan data, when the server answers 403', async () => {
    get.mockRejectedValue(refusal(403))
    renderPage()

    expect(await screen.findByRole('heading', { name: 'Access denied' })).toBeInTheDocument()
    expect(screen.queryByTestId('floor-plan-svg')).not.toBeInTheDocument()
    expect(screen.queryByText('Computer Lab 2')).not.toBeInTheDocument()
    // A refusal is final — no retry storm against a closed door.
    expect(get).toHaveBeenCalledTimes(1)
  })

  it('shows the Forbidden page when the session has been lost (401)', async () => {
    get.mockRejectedValue(refusal(401))
    renderPage()

    expect(await screen.findByRole('heading', { name: 'Access denied' })).toBeInTheDocument()
  })

  it('says so when the room cannot be found', async () => {
    get.mockRejectedValue(refusal(404))
    renderPage()

    expect(await screen.findByText(/could not be found/)).toBeInTheDocument()
    expect(screen.queryByTestId('floor-plan-svg')).not.toBeInTheDocument()
  })

  it('shows an empty state, not a broken map, for a room with no active layout', async () => {
    get.mockResolvedValue({
      data: { data: roomPlan({ layout: null, pcs: [], unplaced_count: 3 }) },
    })
    renderPage()

    expect(await screen.findByText('No floor plan for this room yet')).toBeInTheDocument()
    expect(screen.getByText(/3 units are in this room/)).toBeInTheDocument()
    expect(screen.queryByTestId('floor-plan-svg')).not.toBeInTheDocument()
  })

  it('never asks the server to change anything', async () => {
    get.mockResolvedValue({ data: { data: roomPlan() } })
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    // The mocked api has no post/put/patch/delete at all; a write would throw.
    expect(get).toHaveBeenCalledTimes(1)
  })
})
