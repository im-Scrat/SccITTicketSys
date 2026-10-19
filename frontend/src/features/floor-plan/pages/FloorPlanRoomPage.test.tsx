import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PreviewModeContext } from '@/contexts/PreviewModeContext'
import { stubSvgGeometry } from '@/test/svgGeometry'
import { pc, roomPlan, status, unplacedPc } from '../test/fixtures'
import type { RoomPlan } from '../types'
import FloorPlanRoomPage from './FloorPlanRoomPage'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
  },
}))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({
    user: { id: 'user-1' },
    hasPermission: () => true,
    hasRole: () => true,
    isAuthenticated: true,
    isReady: true,
    isLoading: false,
    permissions: [],
  }),
}))

/** Echo stand-in: records the room channel's listeners so a test can deliver an event. */
const listeners: Record<string, (payload: unknown) => void> = {}
vi.mock('@/services/echo', () => {
  const channel: Record<string, unknown> = {}
  Object.assign(channel, {
    listen: (event: string, cb: (payload: unknown) => void) => {
      listeners[event] = cb
      return channel
    },
    subscribed: () => channel,
    error: () => channel,
    here: () => channel,
    joining: () => channel,
    leaving: () => channel,
    whisper: () => channel,
    listenForWhisper: () => channel,
  })
  return {
    getEcho: () =>
      Promise.resolve({ private: () => channel, join: () => channel, leave: () => undefined }),
  }
})

function renderPage(plan: RoomPlan | Error, options: { preview?: boolean } = {}) {
  // Only the room plan answers; the details panel's asset and repair lookups
  // are refused, which it shows as "could not be loaded".
  get.mockImplementation((url: string) => {
    if (url !== '/admin/floor-plan/rooms/room-uuid-1') return Promise.reject(axiosError(404))
    return plan instanceof Error ? Promise.reject(plan) : Promise.resolve({ data: { data: plan } })
  })

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <PreviewModeContext.Provider value={options.preview ?? false}>
        <MemoryRouter initialEntries={['/app/floor-plan/rooms/room-uuid-1']}>
          <Routes>
            <Route path="/app/floor-plan/rooms/:id" element={<FloorPlanRoomPage />} />
          </Routes>
        </MemoryRouter>
      </PreviewModeContext.Provider>
    </QueryClientProvider>,
  )
}

function axiosError(status: number, data: unknown = {}) {
  return new AxiosError('refused', String(status), undefined, undefined, {
    status,
    data,
    statusText: '',
    headers: {},
    config: {} as never,
  })
}

const node = (name: string): HTMLElement => {
  const found = screen
    .getAllByTestId('floor-plan-node')
    .find((el) => el.getAttribute('aria-label')?.startsWith(`${name},`))
  if (!found) throw new Error(`No workstation named ${name}`)
  return found
}

beforeEach(() => {
  for (const key of Object.keys(listeners)) delete listeners[key]
  get.mockReset()
  put.mockReset()
  post.mockReset()
  window.sessionStorage.clear()
  stubSvgGeometry()
  window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as never
})

describe('opening a room', () => {
  it('asks for the room named in the URL and draws each placed workstation', async () => {
    renderPage(roomPlan())
    await screen.findByRole('heading', { level: 1, name: 'Computer Lab 2' })

    expect(get).toHaveBeenCalledWith('/admin/floor-plan/rooms/room-uuid-1')
    expect(screen.getAllByTestId('floor-plan-node')).toHaveLength(3)
    expect(node('PC-03')).toHaveAccessibleName('PC-03, Needs Fix, at x 300, y 120')
  })

  it('offers the breadcrumb back to the overview', async () => {
    renderPage(roomPlan())
    const crumbs = await screen.findByRole('navigation', { name: 'Breadcrumb' })
    expect(within(crumbs).getByRole('link', { name: 'Floorplan' })).toHaveAttribute(
      'href',
      '/app/floor-plan',
    )
  })

  it('shows the Forbidden page when the server refuses', async () => {
    renderPage(axiosError(403))
    expect(await screen.findByRole('heading', { name: 'Access denied' })).toBeInTheDocument()
  })

  it('explains a room that no longer exists, with a way back', async () => {
    renderPage(axiosError(404))
    expect(await screen.findByText('This room could not be found')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to Floorplan' })).toHaveAttribute(
      'href',
      '/app/floor-plan',
    )
  })

  it('says the status is recorded, never claiming to monitor connections', async () => {
    renderPage(roomPlan())
    await screen.findByRole('heading', { level: 1 })
    expect(screen.getByText(/does not monitor whether a PC is switched on/)).toBeInTheDocument()
  })
})

describe('a room with no Floorplan yet', () => {
  it('creates and activates the first layout in one step', async () => {
    post.mockResolvedValue({
      data: { data: { version: 1, width: 1200, height: 800, grid_size: 20, is_active: true } },
    })
    renderPage(roomPlan({ layout: null, pcs: [], unplaced: [unplacedPc(1)] }))

    await userEvent.click(await screen.findByRole('button', { name: 'Set up Floorplan' }))

    expect(post).toHaveBeenCalledWith('/admin/floor-plan/rooms/room-uuid-1/layouts', {
      width: 1200,
      height: 800,
      grid_size: 20,
      activate: true,
    })
  })

  it('tells a viewer who may not edit, instead of showing a form', async () => {
    renderPage(roomPlan({ layout: null, editor: { can_edit: false, snap_to_grid: true } }))
    expect(await screen.findByText(/has not set this room up yet/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Set up Floorplan' })).not.toBeInTheDocument()
  })
})

describe('view mode', () => {
  it('offers no editing tools until "Edit layout"', async () => {
    renderPage(roomPlan())
    await screen.findByRole('heading', { level: 1 })

    expect(screen.queryByRole('button', { name: 'Undo' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Edit layout' }))
    expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled()
  })

  it('opens a workstation’s details when it is chosen', async () => {
    renderPage(roomPlan())
    await screen.findByRole('heading', { level: 1 })

    fireEvent.keyDown(node('PC-01'), { key: 'Enter' })

    const details = screen.getByRole('region', { name: 'Details' })
    expect(within(details).getByRole('heading', { name: 'PC-01' })).toBeInTheDocument()
  })

  it('is view-only inside the import review preview', async () => {
    renderPage(roomPlan(), { preview: true })
    await screen.findByRole('heading', { level: 1 })
    expect(screen.queryByRole('button', { name: 'Edit layout' })).not.toBeInTheDocument()
  })
})

describe('editing and saving', () => {
  async function startEditing(plan = roomPlan()) {
    renderPage(plan)
    await screen.findByRole('heading', { level: 1 })
    await userEvent.click(screen.getByRole('button', { name: 'Edit layout' }))
  }

  it('drags a workstation and snaps it to the grid', async () => {
    await startEditing()
    const pc1 = node('PC-01')

    fireEvent.pointerDown(pc1, { pointerId: 1, button: 0, clientX: 100, clientY: 120 })
    fireEvent.pointerMove(screen.getByTestId('floor-plan-svg'), {
      pointerId: 1,
      clientX: 147,
      clientY: 123,
    })
    fireEvent.pointerUp(screen.getByTestId('floor-plan-svg'), {
      pointerId: 1,
      clientX: 147,
      clientY: 123,
    })

    expect(node('PC-01')).toHaveAccessibleName('PC-01, Available, at x 140, y 120')
    expect(screen.getByText('Unsaved changes (1)')).toBeInTheDocument()
  })

  it('cancels a drag that would put two workstations on one spot, and says why', async () => {
    await startEditing()

    fireEvent.pointerDown(node('PC-01'), { pointerId: 1, button: 0, clientX: 100, clientY: 120 })
    fireEvent.pointerMove(screen.getByTestId('floor-plan-svg'), {
      pointerId: 1,
      clientX: 290,
      clientY: 120,
    })
    fireEvent.pointerUp(screen.getByTestId('floor-plan-svg'), {
      pointerId: 1,
      clientX: 290,
      clientY: 120,
    })

    expect(node('PC-01')).toHaveAccessibleName('PC-01, Available, at x 100, y 120')
    expect(await screen.findByText(/would overlap PC-03/)).toBeInTheDocument()
  })

  it('moves the selection with the arrow keys, and undoes it', async () => {
    await startEditing()

    fireEvent.keyDown(node('PC-01'), { key: 'Enter' })
    fireEvent.keyDown(document.body, { key: 'ArrowDown' })
    expect(node('PC-01')).toHaveAccessibleName('PC-01, Available, at x 100, y 140')

    fireEvent.keyDown(document.body, { key: 'z', ctrlKey: true })
    expect(node('PC-01')).toHaveAccessibleName('PC-01, Available, at x 100, y 120')
  })

  it('places an unplaced PC and saves the whole layout with the revision it was based on', async () => {
    const plan = roomPlan({
      pcs: [pc(1, 'available')],
      unplaced: [unplacedPc(1, 'pending_repair')],
    })
    const saved = roomPlan({
      pcs: [
        pc(1, 'available'),
        pc(9, 'pending_repair', { id: 'unplaced-uuid-1', name: 'PC-U1', x: 500, y: 300 }),
      ],
      layout: { ...plan.layout!, revision: 4 },
    })
    put.mockResolvedValue({ data: { data: saved } })
    await startEditing(plan)

    const panel = screen.getByRole('complementary', { name: 'Workstations panel' })
    await userEvent.click(within(panel).getByRole('button', { name: /Place PC-U1/ }))
    // From here on the server holds the saved revision, as a real one would.
    get.mockImplementation((url: string) =>
      url === '/admin/floor-plan/rooms/room-uuid-1'
        ? Promise.resolve({ data: { data: saved } })
        : Promise.reject(axiosError(404)),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(put).toHaveBeenCalled())
    const [url, body] = put.mock.calls[0]
    expect(url).toBe('/admin/floor-plan/rooms/room-uuid-1/layouts/1')
    expect(body.expected_revision).toBe(3)
    expect(body.workstations.map((w: { pc_unit: string }) => w.pc_unit)).toEqual([
      'pc-uuid-1',
      'unplaced-uuid-1',
    ])
    expect(await screen.findByText('Floorplan saved.')).toBeInTheDocument()
  })

  it('never overwrites a newer save — it shows what changed and lets the administrator combine', async () => {
    const current = roomPlan({
      layout: { ...roomPlan().layout!, revision: 4, updated_by: { name: 'Ben Cruz' } },
    })
    put.mockRejectedValue(axiosError(409, { code: 'layout_stale', message: 'stale', current }))
    await startEditing()

    fireEvent.keyDown(node('PC-01'), { key: 'Enter' })
    fireEvent.keyDown(document.body, { key: 'ArrowDown' })
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    const dialog = await screen.findByRole('dialog', {
      name: 'This Floorplan changed while you were editing',
    })
    expect(within(dialog).getByText(/Ben Cruz saved it after you opened it/)).toBeInTheDocument()
    expect(within(dialog).getByText(/1 of your changes apply cleanly/)).toBeInTheDocument()
  })

  it('outlines what the server refused and says how many', async () => {
    put.mockRejectedValue(
      axiosError(422, {
        message: 'invalid',
        errors: { 'workstations.0.x': ['This workstation overlaps another one.'] },
      }),
    )
    await startEditing()

    fireEvent.keyDown(node('PC-01'), { key: 'Enter' })
    fireEvent.keyDown(document.body, { key: 'ArrowDown' })
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findAllByText(/1 object needs attention before saving/)).not.toHaveLength(0)
    expect(node('PC-01')).toHaveAttribute('aria-invalid', 'true')
  })

  it('keeps unsaved work in this tab so it can be restored after leaving', async () => {
    await startEditing()

    fireEvent.keyDown(node('PC-01'), { key: 'Enter' })
    fireEvent.keyDown(document.body, { key: 'ArrowDown' })

    await waitFor(() =>
      expect(
        window.sessionStorage.getItem('sccit.floorplanDraft.user-1.room-uuid-1'),
      ).not.toBeNull(),
    )
  })
})

describe('live updates', () => {
  it('applies a status change from the channel without touching the draft', async () => {
    renderPage(roomPlan())
    await screen.findByRole('heading', { level: 1 })
    await waitFor(() => expect(listeners['.floor-plan.pc-status-changed']).toBeDefined())

    act(() => {
      listeners['.floor-plan.pc-status-changed']({
        pc: { id: 'pc-uuid-1', name: 'PC-01', unit_code: 'LAB-1', status: status('needs_fix') },
      })
    })

    // TanStack Query notifies observers on its own schedule, not synchronously.
    await waitFor(() =>
      expect(node('PC-01')).toHaveAccessibleName('PC-01, Needs Fix, at x 100, y 120'),
    )
  })
})
