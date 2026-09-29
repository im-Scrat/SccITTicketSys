import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useViewportStore } from '../stores/useViewportStore'
import { pc, roomPlan, unplacedPc } from '../test/fixtures'
import type { PlacedPc, RoomPlan } from '../types'
import FloorPlanRoomPage from './FloorPlanRoomPage'

/**
 * WP-D through the page: the optimistic preview, reconciliation to the
 * server's stored point, rollback on refusal, the live region, and the
 * numeric route. The API is mocked at the transport, so these prove what the
 * page does with each kind of server answer.
 */

const get = vi.fn()
const patch = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    patch: (...args: unknown[]) => patch(...args),
  },
}))

// WP-E: stub the realtime subscription every render of this page now opens,
// so it never resolves through the mocked `api` above (double-counting `get`)
// or attempts a real socket. Reconciliation itself is covered in
// useFloorPlanChannel.test.tsx.
vi.mock('@/services/echo', () => {
  function stubChannel(): { listen: () => unknown } {
    const channel = { listen: () => channel }
    return channel
  }
  return { getEcho: () => Promise.resolve({ private: stubChannel, leave: () => undefined }) }
})

function editablePlan(overrides: Partial<RoomPlan> = {}): RoomPlan {
  return roomPlan({
    pcs: [pc(1, 'online', { x: 120, y: 80 }), pc(2, 'offline', { x: 400, y: 300 })],
    editor: { can_edit: true, snap_to_grid: true },
    ...overrides,
  })
}

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

  return queryClient
}

function serverError(status: number, data: object) {
  return new AxiosError('refused', String(status), undefined, undefined, {
    status,
    data,
    statusText: '',
    headers: {},
    config: {} as never,
  })
}

/** A promise the test resolves by hand, to look at the page mid-request. */
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

const unit = (name = /^PC-01/) => screen.getByRole('button', { name })
const announcer = () => screen.getByTestId('floor-plan-announcer')

function drag(target: Element, to: { x: number; y: number }, from = { x: 120, y: 80 }) {
  fireEvent.pointerDown(target, {
    pointerId: 5,
    clientX: from.x,
    clientY: from.y,
    button: 0,
    pointerType: 'mouse',
  })
  fireEvent.pointerMove(target, {
    pointerId: 5,
    clientX: to.x,
    clientY: to.y,
    pointerType: 'mouse',
  })
  fireEvent.pointerUp(target, { pointerId: 5, pointerType: 'mouse' })
}

beforeEach(() => {
  get.mockReset()
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

describe('placing from the map', () => {
  it('sends the drop to the layout version and unit, then shows the server’s point', async () => {
    get.mockResolvedValue({ data: { data: editablePlan() } })
    const answer = deferred<{ data: { data: PlacedPc } }>()
    patch.mockReturnValue(answer.promise)
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    drag(unit(), { x: 167, y: 103 })

    // Sent after the optimistic update settles (onMutate awaits cancelQueries).
    await waitFor(() =>
      expect(patch).toHaveBeenCalledWith(
        '/admin/floor-plan/rooms/room-uuid-1/layouts/1/positions/pc-uuid-1',
        { x: 160, y: 100, snap: true },
      ),
    )

    // Optimistic: drawn at the preview and marked busy while the server decides.
    await waitFor(() => expect(unit().getAttribute('transform')).toBe('translate(160 100)'))
    expect(unit()).toHaveAttribute('aria-busy', 'true')

    // The server stored somewhere else (it is the authority): the map follows it.
    await act(async () => {
      answer.resolve({ data: { data: pc(1, 'online', { x: 180, y: 100 }) } })
    })

    await waitFor(() => expect(unit().getAttribute('transform')).toBe('translate(180 100)'))
    expect(unit()).not.toHaveAttribute('aria-busy')
    expect(announcer()).toHaveTextContent('PC-01 placed at x 180, y 100, aligned to the grid.')
    expect(
      within(screen.getByRole('table', { name: /Units placed in/ })).getByText('180, 100'),
    ).toBeInTheDocument()
  })

  it('announces a committed keyboard move in the polite live region', async () => {
    const user = userEvent.setup()
    get.mockResolvedValue({ data: { data: editablePlan() } })
    patch.mockResolvedValue({ data: { data: pc(1, 'online', { x: 140, y: 80 }) } })
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    expect(announcer()).toHaveAttribute('aria-live', 'polite')

    unit().focus()
    await user.keyboard('{Enter}{ArrowRight}{Enter}')

    await waitFor(() => expect(announcer()).toHaveTextContent('PC-01 placed at x 140, y 80.'))
    expect(patch).toHaveBeenCalledWith(expect.any(String), { x: 140, y: 80, snap: true })
  })

  it('puts the unit back and explains, when the server refuses the spot', async () => {
    get.mockResolvedValue({ data: { data: editablePlan() } })
    patch.mockRejectedValue(
      serverError(422, {
        code: 'position_occupied',
        message: 'Another PC unit already stands at that position.',
      }),
    )
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    drag(unit(), { x: 400, y: 300 })

    await screen.findByRole('alert')
    expect(screen.getByRole('alert')).toHaveTextContent(
      /Another unit already stands at that position/,
    )
    await waitFor(() => expect(unit().getAttribute('transform')).toBe('translate(120 80)'))
    expect(announcer()).toHaveTextContent(/PC-01 could not be moved/)
  })

  it('reloads the plan when the layout is no longer the active one (409)', async () => {
    get.mockResolvedValue({ data: { data: editablePlan() } })
    patch.mockRejectedValue(serverError(409, { code: 'layout_not_active', message: '…' }))
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })
    expect(get).toHaveBeenCalledTimes(1)

    drag(unit(), { x: 300, y: 300 })

    // Shown (the alert) and said (the live region) — two places, one message.
    expect(await screen.findByRole('alert')).toHaveTextContent(/no longer the active one/)
    expect(announcer()).toHaveTextContent(/no longer the active one/)
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
  })

  it('keeps a second unit’s in-flight move when the first is refused', async () => {
    get.mockResolvedValue({ data: { data: editablePlan() } })
    const first = deferred<never>()
    const second = deferred<{ data: { data: PlacedPc } }>()
    patch.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    drag(unit(/^PC-01/), { x: 200, y: 200 })
    drag(unit(/^PC-02/), { x: 600, y: 400 }, { x: 400, y: 300 })

    await act(async () => {
      first.reject(serverError(422, { code: 'position_occupied' }))
    })

    // PC-01 is rolled back; PC-02's optimistic move is untouched by that rollback.
    await waitFor(() => expect(unit(/^PC-01/).getAttribute('transform')).toBe('translate(120 80)'))
    expect(unit(/^PC-02/).getAttribute('transform')).toBe('translate(600 400)')

    await act(async () => {
      second.resolve({ data: { data: pc(2, 'offline', { x: 600, y: 400 }) } })
    })
    expect(unit(/^PC-02/).getAttribute('transform')).toBe('translate(600 400)')
  })
})

describe('placing by numbers', () => {
  it('moves the selected unit to exact coordinates and reconciles to the answer', async () => {
    const user = userEvent.setup()
    get.mockResolvedValue({ data: { data: editablePlan() } })
    patch.mockResolvedValue({ data: { data: pc(1, 'online', { x: 520, y: 240 }) } })
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    await user.selectOptions(screen.getByLabelText('Unit'), 'pc-uuid-1')
    expect(screen.getByLabelText('X position')).toHaveValue(120)

    await user.clear(screen.getByLabelText('X position'))
    await user.type(screen.getByLabelText('X position'), '517')
    await user.clear(screen.getByLabelText('Y position'))
    await user.type(screen.getByLabelText('Y position'), '243')
    await user.click(screen.getByRole('button', { name: 'Move unit' }))

    expect(patch).toHaveBeenCalledWith(expect.stringContaining('/positions/pc-uuid-1'), {
      x: 517,
      y: 243,
      snap: true,
    })
    await waitFor(() => expect(unit().getAttribute('transform')).toBe('translate(520 240)'))
    await waitFor(() => expect(screen.getByLabelText('X position')).toHaveValue(520))
  })

  it('refuses a point off the canvas before sending it', async () => {
    const user = userEvent.setup()
    get.mockResolvedValue({ data: { data: editablePlan() } })
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    await user.selectOptions(screen.getByLabelText('Unit'), 'pc-uuid-1')
    await user.clear(screen.getByLabelText('X position'))
    await user.type(screen.getByLabelText('X position'), '1200')
    await user.click(screen.getByRole('button', { name: 'Move unit' }))

    expect(await screen.findByText('Enter an x position between 0 and 1000.')).toBeInTheDocument()
    expect(patch).not.toHaveBeenCalled()
  })

  it('shows the server’s own field message when it rejects the bounds', async () => {
    const user = userEvent.setup()
    get.mockResolvedValue({ data: { data: editablePlan() } })
    patch.mockRejectedValue(
      serverError(422, {
        message: 'The given data was invalid.',
        errors: { y: ['The y position must be between 0 and 400.'] },
      }),
    )
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    await user.selectOptions(screen.getByLabelText('Unit'), 'pc-uuid-1')
    await user.click(screen.getByRole('button', { name: 'Move unit' }))

    // The layout shrank on the server since the page loaded; its answer wins.
    expect(
      await screen.findAllByText('The y position must be between 0 and 400.'),
    ).not.toHaveLength(0)
  })

  it('places an unplaced unit, which then joins the map', async () => {
    const user = userEvent.setup()
    get.mockResolvedValue({
      data: { data: editablePlan({ unplaced: [unplacedPc(9, 'offline')], unplaced_count: 1 }) },
    })
    patch.mockResolvedValue({
      data: {
        data: {
          ...unplacedPc(9, 'offline'),
          x: 60,
          y: 60,
          rotation: 0,
          z_index: 0,
        } satisfies PlacedPc,
      },
    })
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    expect(screen.getByRole('heading', { name: /Not on the plan yet/ })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Place PC-U9 on the plan' }))

    expect(patch).toHaveBeenCalledWith(
      expect.stringContaining('/positions/unplaced-uuid-9'),
      expect.objectContaining({ snap: true }),
    )
    await screen.findByRole('button', { name: /^PC-U9, Offline, at x 60, y 60$/ })
    expect(screen.queryByRole('heading', { name: /Not on the plan yet/ })).toBeNull()
  })
})

describe('when the server says the plan is not editable', () => {
  it('offers no placement at all — map only', async () => {
    get.mockResolvedValue({
      data: { data: editablePlan({ editor: { can_edit: false, snap_to_grid: true } }) },
    })
    renderPage()
    await screen.findByRole('heading', { name: 'Computer Lab 2' })

    expect(screen.queryByRole('button', { name: /^PC-01/ })).toBeNull()
    expect(screen.getByRole('img', { name: /^PC-01/ })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Place a unit by position' })).toBeNull()
  })
})
