import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { roomSummary } from '../test/fixtures'
import type { FloorPlanRoomsResponse } from '../types'
import FloorPlanPage from './FloorPlanPage'

const get = vi.fn()
vi.mock('@/services/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

let permissions: string[] = []
vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({
    user: { id: 'user-1' },
    hasPermission: (p: string) => permissions.includes(p),
  }),
}))

function response(overrides: Partial<FloorPlanRoomsResponse> = {}): FloorPlanRoomsResponse {
  return {
    data: [
      roomSummary(),
      roomSummary({ id: 'room-uuid-2', name: 'Faculty Office', layout: null, preview: null }),
    ],
    meta: { current_page: 1, last_page: 1, per_page: 24, total: 2, unassigned_pcs: 2 },
    ...overrides,
  }
}

function renderPage(body: FloorPlanRoomsResponse = response()) {
  get.mockImplementation((url: string) =>
    url === '/admin/floor-plan/rooms'
      ? Promise.resolve({ data: body })
      : Promise.resolve({ data: { data: [] } }),
  )
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <FloorPlanPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  get.mockReset()
  permissions = []
})

describe('the Floorplan overview', () => {
  it('shows one card per room, each linking to its editor', async () => {
    renderPage()
    const rooms = await screen.findByRole('list', { name: 'Rooms' })

    expect(within(rooms).getByRole('link', { name: 'Computer Lab 2' })).toHaveAttribute(
      'href',
      '/app/floor-plan/rooms/room-uuid-1',
    )
    expect(within(rooms).getByRole('link', { name: 'Faculty Office' })).toHaveAttribute(
      'href',
      '/app/floor-plan/rooms/room-uuid-2',
    )
  })

  it('says how much of each room is placed and how its machines stand, in words', async () => {
    renderPage()
    await screen.findByRole('list', { name: 'Rooms' })

    expect(screen.getByText(/placed · 2 need attention/)).toBeInTheDocument()
    expect(screen.getByText('Set up its Floorplan')).toBeInTheDocument()
  })

  it('counts PCs with no room and links to them in Assets', async () => {
    renderPage()
    const link = await screen.findByRole('link', { name: /Unassigned PCs/ })
    expect(link).toHaveAttribute('href', '/app/assets/list?tab=pc-units&unassigned=1')
  })

  it('filters on the server, by Floorplan state', async () => {
    renderPage()
    await screen.findByRole('list', { name: 'Rooms' })

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Floorplan' }), 'not_set_up')

    await waitFor(() =>
      expect(get).toHaveBeenCalledWith('/admin/floor-plan/rooms', {
        params: expect.objectContaining({ layout: 'not_set_up', page: 1 }),
      }),
    )
  })

  it('explains an empty result and offers to clear the filters', async () => {
    renderPage(
      response({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 24, total: 0, unassigned_pcs: 0 },
      }),
    )
    await userEvent.type(screen.getByRole('searchbox'), 'zzz')

    expect(await screen.findByText('No rooms match')).toBeInTheDocument()
  })

  it('offers "New room" only to someone who may create rooms', async () => {
    renderPage()
    await screen.findByRole('list', { name: 'Rooms' })
    expect(screen.queryByRole('button', { name: 'New room' })).not.toBeInTheDocument()
  })

  it('shows "New room" with locations.create', async () => {
    permissions = ['locations.create']
    renderPage()
    expect(await screen.findByRole('button', { name: 'New room' })).toBeInTheDocument()
  })
})
