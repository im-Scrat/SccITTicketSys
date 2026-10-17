import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RoomLookupOption } from '@/services/lookups'
import { type WhereValue, WhereIsItPicker } from './WhereIsItPicker'

const get = vi.fn()

vi.mock('@/services/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}))

const PC = '11111111-1111-4111-8111-111111111111'
const PROJECTOR = '22222222-2222-4222-8222-222222222222'

function room(
  id: string,
  name: string,
  type: [string, string],
  floor = 'Ground floor',
): RoomLookupOption {
  return {
    id,
    name,
    code: name.toUpperCase().replace(/\s+/g, '-'),
    room_type: type[0],
    room_type_label: type[1],
    floor: { id: `floor-${id}`, floor_number: 1, name: floor },
    building: { id: 'b-1', name: 'Buena' },
    label: `Buena · ${floor} · ${name}`,
  }
}

const LAB = room('room-lab', 'Lab A', ['laboratory', 'Laboratory'])
const OFFICE = room('room-office', 'Office room', ['office', 'Office'], 'First floor')
const STORE = room('room-store', 'Store', ['storage', 'Storage'])

type Routes = {
  buildings?: () => Promise<unknown>
  rooms?: (params: Record<string, unknown>) => Promise<unknown>
  equipment?: (params: Record<string, unknown>) => Promise<unknown>
}

const ok = (data: unknown) => Promise.resolve({ data: { data } })

let routes: Routes

beforeEach(() => {
  get.mockReset()
  routes = {
    buildings: () => ok([{ id: 'b-1', name: 'Buena', code: 'BUE' }]),
    rooms: () => ok([LAB, OFFICE, STORE]),
    equipment: (params) =>
      ok(
        params.room === LAB.id
          ? {
              pc_units: [
                { id: PC, label: 'PC 01 (PC-LA-01)', identifier: 'PC-LA-01', location: null },
              ],
              assets: [
                {
                  id: PROJECTOR,
                  label: 'Epson projector (AST-0001)',
                  identifier: 'AST-0001',
                  location: null,
                },
              ],
            }
          : { pc_units: [], assets: [] },
      ),
  }
  get.mockImplementation((url: string, config?: { params?: Record<string, unknown> }) => {
    const params = config?.params ?? {}
    if (url === '/lookups/buildings') return routes.buildings!()
    if (url === '/lookups/rooms') return routes.rooms!(params)
    if (url === '/lookups/equipment') return routes.equipment!(params)
    return Promise.reject(new Error(`unexpected GET ${url}`))
  })
})

function Harness({ initial }: { initial?: WhereValue }) {
  const [value, setValue] = useState<WhereValue>(initial ?? { building: '', room: '', item: '' })
  return (
    <>
      <WhereIsItPicker value={value} onChange={setValue} />
      <output data-testid="value">{JSON.stringify(value)}</output>
    </>
  )
}

function renderPicker(initial?: WhereValue) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <Harness initial={initial} />
    </QueryClientProvider>,
  )
}

const value = (): WhereValue => JSON.parse(screen.getByTestId('value').textContent ?? '{}')
const roomGroup = () => screen.getByRole('radiogroup', { name: 'Which room?' })
const itemGroup = () => screen.getByRole('radiogroup', { name: 'Which item has the problem?' })
async function roomRadio(name: RegExp) {
  const group = await screen.findByRole('radiogroup', { name: 'Which room?' })
  return within(group).findByRole('radio', { name })
}
async function itemRadio(name: RegExp) {
  const group = await screen.findByRole('radiogroup', { name: 'Which item has the problem?' })
  return within(group).findByRole('radio', { name })
}
const callsTo = (url: string) => get.mock.calls.filter(([called]) => called === url)

describe('WhereIsItPicker — building', () => {
  it('chooses the only building for the reporter, then lists its rooms', async () => {
    renderPicker()

    await waitFor(() => expect(value().building).toBe('b-1'))
    expect(await roomRadio(/lab a/i)).toBeInTheDocument()
    expect(callsTo('/lookups/rooms')[0][1]).toEqual({
      params: { building: 'b-1', sort: 'type', limit: 200 },
    })
  })

  it('does not choose for the reporter when there are several buildings', async () => {
    routes.buildings = () =>
      ok([
        { id: 'b-1', name: 'Buena', code: 'BUE' },
        { id: 'b-2', name: 'Annex', code: 'ANX' },
      ])
    renderPicker()

    const select = await screen.findByRole('combobox', { name: 'Building' })
    await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(3))
    expect(select).toHaveValue('')
    expect(within(select).getAllByRole('option')[0]).toHaveTextContent('Choose a building…')
    expect(screen.queryByRole('radiogroup', { name: 'Which room?' })).not.toBeInTheDocument()
    expect(callsTo('/lookups/rooms')).toHaveLength(0)
  })

  it('says how to carry on when no building exists', async () => {
    routes.buildings = () => ok([])
    renderPicker()

    expect(await screen.findByText(/no buildings have been set up yet/i)).toBeInTheDocument()
  })

  it('offers a retry when the buildings cannot be loaded', async () => {
    let attempts = 0
    routes.buildings = () =>
      ++attempts === 1 ? Promise.reject(new Error('offline')) : ok([{ id: 'b-1', name: 'Buena' }])
    renderPicker()

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The list of buildings could not be loaded.')
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }))

    await waitFor(() => expect(value().building).toBe('b-1'))
  })
})

describe('WhereIsItPicker — rooms', () => {
  it('lists the rooms in the server’s order, each with its type and floor', async () => {
    renderPicker()

    await roomRadio(/lab a/i)
    const group = roomGroup()
    const rows = within(group).getAllByRole('radio')

    expect(rows[0]).toHaveAccessibleName(/not sure which room/i)
    expect(rows[0]).toBeChecked()
    expect(rows.slice(1).map((radio) => radio.getAttribute('value'))).toEqual([
      LAB.id,
      OFFICE.id,
      STORE.id,
    ])
    expect(within(group).getByText('LAB-A · Laboratory · Ground floor')).toBeInTheDocument()
    expect(within(group).getByText('OFFICE-ROOM · Office · First floor')).toBeInTheDocument()
  })

  it('offers no filter box for a short list', async () => {
    renderPicker()
    await roomRadio(/lab a/i)

    expect(screen.queryByRole('searchbox', { name: 'Find a room' })).not.toBeInTheDocument()
  })

  it('filters a long list as you type, keeping the count announced', async () => {
    const many = Array.from({ length: 10 }, (_, index) =>
      room(`room-${index}`, `Classroom ${index + 1}`, ['classroom', 'Classroom']),
    )
    routes.rooms = () => ok([LAB, ...many])
    renderPicker()
    await roomRadio(/lab a/i)

    await userEvent.type(screen.getByRole('searchbox', { name: 'Find a room' }), 'lab')

    const shown = within(roomGroup())
      .getAllByRole('radio')
      .map((radio) => radio.getAttribute('value'))
    expect(shown).toEqual(['', LAB.id])
    expect(screen.getByText('1 room matches')).toBeInTheDocument()
    // Filtering a complete list never goes back to the server.
    expect(callsTo('/lookups/rooms')).toHaveLength(1)
  })

  it('says what the filter matches, and matches it — type and floor included', async () => {
    const many = Array.from({ length: 10 }, (_, index) =>
      room(`room-${index}`, `Classroom ${index + 1}`, ['classroom', 'Classroom']),
    )
    routes.rooms = () => ok([LAB, OFFICE, ...many])
    renderPicker()
    await roomRadio(/lab a/i)

    const search = screen.getByRole('searchbox', { name: 'Find a room' })
    expect(search).toHaveAccessibleDescription('Type part of its name, code, type or floor.')

    await userEvent.type(search, 'first floor')
    expect(
      within(roomGroup())
        .getAllByRole('radio')
        .map((radio) => radio.getAttribute('value')),
    ).toEqual(['', OFFICE.id])
  })

  it('keeps the chosen room on screen when a filter would hide it', async () => {
    const many = Array.from({ length: 10 }, (_, index) =>
      room(`room-${index}`, `Classroom ${index + 1}`, ['classroom', 'Classroom']),
    )
    routes.rooms = () => ok([LAB, ...many])
    renderPicker()

    await userEvent.click(await roomRadio(/lab a/i))
    await userEvent.type(screen.getByRole('searchbox', { name: 'Find a room' }), 'classroom 3')

    const lab = within(roomGroup()).getByRole('radio', { name: /lab a/i })
    expect(lab).toBeChecked()
    expect(within(roomGroup()).getByRole('radio', { name: /classroom 3/i })).toBeInTheDocument()
  })

  it('asks the server only when the list was cut short at 200', async () => {
    const full = Array.from({ length: 200 }, (_, index) =>
      room(`room-${index}`, `Room ${index + 1}`, ['classroom', 'Classroom']),
    )
    routes.rooms = (params) =>
      params.search ? ok([room('room-far', 'Room 431', ['classroom', 'Classroom'])]) : ok(full)
    renderPicker()
    // A 200-row list is slow to query in jsdom; the filter box is the sign it has loaded.
    const search = await screen.findByRole('searchbox', { name: 'Find a room' }, { timeout: 3000 })

    await userEvent.type(search, '431')

    expect(await screen.findByText('Room 431', {}, { timeout: 3000 })).toBeInTheDocument()
    const searched = callsTo('/lookups/rooms').find(([, config]) => config?.params?.search)
    expect(searched?.[1]).toEqual({
      params: { building: 'b-1', sort: 'type', limit: 200, search: '431' },
    })
  })

  it('shows the server’s answer for a cut-short list as it came — a room found by its number', async () => {
    const full = Array.from({ length: 200 }, (_, index) =>
      room(`room-${index}`, `Room ${index + 1}`, ['classroom', 'Classroom']),
    )
    // The server matches room numbers, which this list does not carry: neither
    // "Physics lab" nor its code contains "431".
    const physics = room('room-physics', 'Physics lab', ['laboratory', 'Laboratory'])
    routes.rooms = (params) => (params.search ? ok([physics]) : ok(full))
    renderPicker()
    const search = await screen.findByRole('searchbox', { name: 'Find a room' }, { timeout: 3000 })
    expect(search).toHaveAccessibleDescription('Type part of its name, code or number.')

    await userEvent.type(search, '431')

    expect(await roomRadio(/physics lab/i)).toBeInTheDocument()
    await waitFor(() =>
      expect(
        within(roomGroup())
          .getAllByRole('radio')
          .map((radio) => radio.getAttribute('value')),
      ).toEqual(['', physics.id]),
    )
  })

  it('scrolls a capped list — and only the list — to a chosen room far down it', async () => {
    const many = Array.from({ length: 12 }, (_, index) =>
      room(`room-${index}`, `Classroom ${index + 1}`, ['classroom', 'Classroom']),
    )
    routes.rooms = () => ok([LAB, ...many])
    // jsdom lays nothing out: make the room list a 100 px box whose checked
    // row sits 400 px down it.
    const rect = vi
      .spyOn(HTMLElement.prototype, 'getBoundingClientRect')
      .mockImplementation(function (this: HTMLElement) {
        if (this.tagName === 'UL' && this.closest('fieldset'))
          return { top: 0, bottom: 100 } as DOMRect
        if (this.tagName === 'LABEL' && this.querySelector('input:checked')) {
          return { top: 400, bottom: 460 } as DOMRect
        }
        return { top: 0, bottom: 0 } as DOMRect
      })

    try {
      renderPicker({ building: 'b-1', room: 'room-11', item: '' })
      const chosen = await roomRadio(/classroom 12/i)
      expect(chosen).toBeChecked()

      const list = chosen.closest('ul') as HTMLUListElement
      await waitFor(() => expect(list.scrollTop).toBe(360))
    } finally {
      rect.mockRestore()
    }
  })

  it('teaches what to do when the building has no rooms', async () => {
    routes.rooms = () => ok([])
    renderPicker()

    expect(await screen.findByText(/no rooms are set up in this building yet/i)).toBeInTheDocument()
    expect(within(roomGroup()).getByRole('radio', { name: /not sure which room/i })).toBeChecked()
  })

  it('offers a retry when the rooms cannot be loaded', async () => {
    let attempts = 0
    routes.rooms = () => (++attempts === 1 ? Promise.reject(new Error('offline')) : ok([LAB]))
    renderPicker()

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The rooms in this building could not be loaded.')
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }))

    expect(await roomRadio(/lab a/i)).toBeInTheDocument()
  })
})

describe('WhereIsItPicker — what a screen reader hears while it loads', () => {
  it('says the rooms are loading, then how many, from one line outside the lists', async () => {
    let answer: (rooms: RoomLookupOption[]) => void = () => {}
    routes.rooms = () =>
      new Promise((resolve) => {
        answer = (rooms) => resolve({ data: { data: rooms } })
      })
    let answerBuildings: () => void = () => {}
    routes.buildings = () =>
      new Promise((resolve) => {
        answerBuildings = () => resolve({ data: { data: [{ id: 'b-1', name: 'Buena' }] } })
      })
    renderPicker()

    // Present, and empty, before there is anything to say — so what is said is heard.
    const status = document.querySelector('p[aria-live="polite"]') as HTMLElement
    expect(status).toBeEmptyDOMElement()
    expect(status.closest('fieldset')).toBeNull()

    answerBuildings()
    await waitFor(() => expect(status).toHaveTextContent('Loading rooms…'))
    // The busy marker is on the list being loaded, not around this line.
    expect(status.closest('[aria-busy="true"]')).toBeNull()

    answer([LAB, OFFICE, STORE])
    await waitFor(() => expect(status).toHaveTextContent('3 rooms in this building'))

    await userEvent.click(await roomRadio(/lab a/i))
    await waitFor(() => expect(status).toHaveTextContent('Loading the equipment in this room…'))
    await waitFor(() => expect(status).toHaveTextContent('1 computer, 1 other item in this room'))
  })
})

describe('WhereIsItPicker — items', () => {
  it('names each item once — the label already ends with its tag', async () => {
    renderPicker()
    await userEvent.click(await roomRadio(/lab a/i))
    await itemRadio(/pc 01/i)

    expect(within(itemGroup()).getAllByText(/PC-LA-01/)).toHaveLength(1)
    expect(within(itemGroup()).getAllByText(/AST-0001/)).toHaveLength(1)
  })

  it('lists the room’s computers and other equipment after a "not about a specific item" choice', async () => {
    renderPicker()
    await userEvent.click(await roomRadio(/lab a/i))

    expect(await itemRadio(/pc 01/i)).toBeInTheDocument()
    const group = itemGroup()
    expect(within(group).getAllByRole('radio')[0]).toHaveAccessibleName(
      /not about a specific item/i,
    )
    expect(within(group).getByRole('group', { name: /computers/i })).toBeInTheDocument()
    expect(within(group).getByRole('group', { name: /other equipment/i })).toBeInTheDocument()
  })

  it('records a PC as pc:<uuid> and other equipment as asset:<uuid> — one answer, never both', async () => {
    renderPicker()
    await userEvent.click(await roomRadio(/lab a/i))

    await userEvent.click(await itemRadio(/pc 01/i))
    expect(value().item).toBe(`pc:${PC}`)

    await userEvent.click(within(itemGroup()).getByRole('radio', { name: /epson projector/i }))
    expect(value()).toEqual({ building: 'b-1', room: LAB.id, item: `asset:${PROJECTOR}` })
  })

  it('asks for the room’s equipment once the room has settled, cancellably', async () => {
    renderPicker()
    await userEvent.click(await roomRadio(/lab a/i))
    await itemRadio(/pc 01/i)

    const [, config] = callsTo('/lookups/equipment')[0]
    expect(config.params).toEqual({ room: LAB.id, limit: 200 })
    expect(config.signal).toBeInstanceOf(AbortSignal)
    // No search is ever sent: the endpoint has none (SDD DD-78).
    expect(config.params).not.toHaveProperty('search')
  })

  it('clears the item when the room changes', async () => {
    renderPicker()
    await userEvent.click(await roomRadio(/lab a/i))
    await userEvent.click(await itemRadio(/epson projector/i))

    await userEvent.click(within(roomGroup()).getByRole('radio', { name: /office room/i }))

    expect(value()).toEqual({ building: 'b-1', room: OFFICE.id, item: '' })
  })

  it('clears the room and the item when the building changes', async () => {
    routes.buildings = () =>
      ok([
        { id: 'b-1', name: 'Buena', code: 'BUE' },
        { id: 'b-2', name: 'Annex', code: 'ANX' },
      ])
    renderPicker()
    const select = await screen.findByRole('combobox', { name: 'Building' })
    await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(3))

    await userEvent.selectOptions(select, 'b-1')
    await userEvent.click(await roomRadio(/lab a/i))
    await userEvent.click(await itemRadio(/pc 01/i))

    await userEvent.selectOptions(select, 'b-2')

    expect(value()).toEqual({ building: 'b-2', room: '', item: '' })
  })

  it('teaches what to do when nothing is listed for the room', async () => {
    renderPicker()
    await userEvent.click(await roomRadio(/store/i))

    expect(
      await screen.findByText(/no computers or other equipment are listed for this room/i),
    ).toBeInTheDocument()
    expect(within(itemGroup()).getAllByRole('radio')).toHaveLength(1)
  })

  it('offers a retry when the equipment cannot be loaded', async () => {
    let attempts = 0
    routes.equipment = () =>
      ++attempts === 1 ? Promise.reject(new Error('offline')) : ok({ pc_units: [], assets: [] })
    renderPicker()
    await userEvent.click(await roomRadio(/lab a/i))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The equipment in this room could not be loaded.')
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }))

    expect(await screen.findByText(/no computers or other equipment/i)).toBeInTheDocument()
  })

  it('lets go of a remembered item the room no longer lists', async () => {
    renderPicker({
      building: 'b-1',
      room: LAB.id,
      item: 'pc:99999999-9999-4999-8999-999999999999',
    })

    await itemRadio(/pc 01/i)
    await waitFor(() => expect(value().item).toBe(''))
  })

  it('lets go of a remembered room that is no longer offered', async () => {
    renderPicker({ building: 'b-1', room: 'room-archived', item: '' })

    await roomRadio(/lab a/i)
    await waitFor(() => expect(value().room).toBe(''))
  })
})
