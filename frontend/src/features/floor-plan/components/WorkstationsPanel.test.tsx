import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { docFromPlan } from '../lib/layoutDocument'
import { placedEquipment, roomPlan, unplacedEquipment, unplacedPc } from '../test/fixtures'
import { WorkstationsPanel } from './WorkstationsPanel'

const get = vi.fn()
const put = vi.fn()
vi.mock('@/services/api', () => ({
  api: { get: (...a: unknown[]) => get(...a), put: (...a: unknown[]) => put(...a) },
}))

const elsewhere = {
  id: 'pc-elsewhere',
  unit_code: 'LABB-PC09',
  pc_name: 'PC-B09',
  status: 'needs_fix',
  room: { id: 'room-b', name: 'Lab B' },
  building: null,
}

function renderPanel(props: Partial<React.ComponentProps<typeof WorkstationsPanel>> = {}) {
  const plan = roomPlan({ unplaced: [unplacedPc(1)] })
  const onPlace = vi.fn()
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <WorkstationsPanel
        plan={plan}
        doc={docFromPlan(plan)!}
        editing
        canMoveIn
        selection={[]}
        onPlace={onPlace}
        onSelect={vi.fn()}
        onAnnounce={vi.fn()}
        {...props}
      />
    </QueryClientProvider>,
  )
  return { onPlace }
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  get.mockResolvedValue({
    data: { data: [elsewhere], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } },
  })
})

describe('the Workstations panel', () => {
  it('lists every machine of the room with its status in words and whether it is placed', () => {
    renderPanel()
    const list = screen.getByRole('list', { name: 'PCs in this room' })

    expect(within(list).getByText('PC-U1').closest('li')).toHaveTextContent('Not placed')
    expect(within(list).getByText('PC-03').closest('li')).toHaveTextContent('Needs Fix')
  })

  it('offers "Place" for an unplaced machine while editing', async () => {
    const { onPlace } = renderPanel()
    await userEvent.click(screen.getByRole('button', { name: 'Place PC-U1' }))
    expect(onPlace).toHaveBeenCalledWith('unplaced-uuid-1')
  })

  it('offers no placing in view mode', () => {
    renderPanel({ editing: false })
    expect(screen.queryByRole('button', { name: 'Place PC-U1' })).not.toBeInTheDocument()
  })

  it('lists other rooms’ PCs with their current room, excluding this room', async () => {
    renderPanel()
    await userEvent.click(screen.getByRole('tab', { name: /Other rooms/ }))

    const list = await screen.findByRole('list', { name: 'PCs in other rooms' })
    expect(within(list).getByText('Lab B')).toBeInTheDocument()
    expect(get).toHaveBeenCalledWith('/admin/pc-units', {
      params: expect.objectContaining({ exclude_room: 'room-uuid-1' }),
    })
  })

  it('moves a PC here only after a confirmation that names its current room', async () => {
    put.mockResolvedValue({ data: { data: {} } })
    renderPanel()
    await userEvent.click(screen.getByRole('tab', { name: /Other rooms/ }))
    await userEvent.click(await screen.findByRole('button', { name: 'Move PC-B09 here' }))

    const dialog = screen.getByRole('dialog', { name: 'Move PC-B09 to Computer Lab 2?' })
    expect(within(dialog).getByText(/It is registered to Lab B/)).toBeInTheDocument()
    expect(put).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Move PC' }))
    await waitFor(() =>
      expect(put).toHaveBeenCalledWith('/admin/pc-units/pc-elsewhere', { room: 'room-uuid-1' }),
    )
  })

  it('hides other rooms from someone who may not move assets', () => {
    renderPanel({ canMoveIn: false })
    expect(screen.queryByRole('tab', { name: /Other rooms/ })).not.toBeInTheDocument()
  })
})

describe('the Workstations panel — confirmed equipment (DD-115)', () => {
  function renderWithEquipment(editing = true) {
    const plan = roomPlan({
      equipment: [placedEquipment(1)],
      unplaced_equipment: [unplacedEquipment(2)],
    })
    const onPlace = vi.fn()
    render(
      <QueryClientProvider client={new QueryClient()}>
        <WorkstationsPanel
          plan={plan}
          doc={docFromPlan(plan)!}
          editing={editing}
          canMoveIn={false}
          selection={[]}
          onPlace={onPlace}
          onSelect={vi.fn()}
          onAnnounce={vi.fn()}
        />
      </QueryClientProvider>,
    )
    return { onPlace }
  }

  it('lists confirmed equipment after the PCs, placed or not', () => {
    renderWithEquipment()
    const list = screen.getByRole('list', { name: 'Equipment in this room' })

    expect(within(list).getByText('Printer 1').closest('li')).toHaveTextContent('Placed')
    expect(within(list).getByText('Printer 2').closest('li')).toHaveTextContent('Not placed')
  })

  it('places unplaced equipment by its eq: id, like a PC', async () => {
    const { onPlace } = renderWithEquipment()

    await userEvent.click(screen.getByRole('button', { name: 'Place Printer 2' }))

    expect(onPlace).toHaveBeenCalledWith('eq:asset-uuid-2')
  })

  it('offers no placing of equipment in view mode', () => {
    renderWithEquipment(false)

    expect(screen.queryByRole('button', { name: 'Place Printer 2' })).not.toBeInTheDocument()
  })
})
