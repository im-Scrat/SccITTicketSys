import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, type AxiosResponse } from 'axios'
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { preparePhoto, UnsupportedPhotoError } from '@/lib/images'
import { API_CATEGORIES } from '../categoryFixtures'
import { readReportDraft, saveReportDraft } from '../hooks/useReportDraft'
import CreateTicketPage from './CreateTicketPage'

const get = vi.fn()
const post = vi.fn()
let role = 'teacher'

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
  },
}))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({
    user: { id: 'user-1' },
    hasRole: (candidate: string) => candidate === role,
    hasPermission: () => true,
  }),
}))

// These bring their own queries and are not what is under test. The similar-
// reports block only records what it was asked to search for.
const duplicates = vi.hoisted(() => ({ searches: [] as string[] }))
vi.mock('../components/DuplicateSuggestions', () => ({
  DuplicateSuggestions: ({ search }: { search: string }) => {
    duplicates.searches.push(search)
    return null
  },
}))
vi.mock('../components/PcAlreadyReported', () => ({
  PcAlreadyReported: ({ pcUnit }: { pcUnit: string }) => <p>already reported on {pcUnit}</p>,
}))

// The pipeline is lib/images.test.ts's subject; here every photo is ready as picked.
vi.mock('@/lib/images', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/images')>()
  return { ...actual, preparePhoto: vi.fn() }
})

const PC = '11111111-1111-4111-8111-111111111111'
const PROJECTOR = '22222222-2222-4222-8222-222222222222'

/** What a teacher receives now: priorities most urgent first, without SLA minutes. */
const OPTIONS = {
  categories: API_CATEGORIES,
  statuses: [],
  tags: [],
  priorities: [
    { value: 'critical', label: 'Urgent', level: 4, color: '#ef4444' },
    { value: 'high', label: 'High', level: 3, color: '#f97316' },
    { value: 'medium', label: 'Medium', level: 2, color: '#3b82f6' },
    { value: 'low', label: 'Low', level: 1, color: '#64748b' },
  ],
  technicians: [],
  uploads: { max_kb: 10240 },
}

const LAB = {
  id: 'room-lab',
  name: 'Lab A',
  code: 'LAB-A',
  room_type: 'laboratory',
  room_type_label: 'Laboratory',
  floor: { id: 'f-1', floor_number: 1, name: 'Ground floor' },
  building: { id: 'b-1', name: 'Buena' },
  label: 'Buena · Ground floor · Lab A',
}
const OFFICE = {
  ...LAB,
  id: 'room-office',
  name: 'Office room',
  code: 'OFF-1',
  room_type: 'office',
  room_type_label: 'Office',
}

const ok = (data: unknown) => Promise.resolve({ data: { data } })
const originalMatchMedia = window.matchMedia

beforeEach(() => {
  role = 'teacher'
  sessionStorage.clear()
  duplicates.searches.length = 0
  get.mockReset()
  post.mockReset()
  vi.mocked(preparePhoto).mockReset()
  vi.mocked(preparePhoto).mockImplementation(async (file) => ({ file, reencoded: false }))
  globalThis.URL.createObjectURL = vi.fn(() => 'blob:preview')
  globalThis.URL.revokeObjectURL = vi.fn()
  window.matchMedia = vi.fn(() => ({
    matches: false,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia

  get.mockImplementation((url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/tickets/options') return ok(OPTIONS)
    if (url === '/lookups/buildings') return ok([{ id: 'b-1', name: 'Buena', code: 'BUE' }])
    if (url === '/lookups/rooms') return ok([LAB, OFFICE])
    if (url === '/lookups/equipment') {
      return ok(
        config?.params?.room === LAB.id
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
      )
    }
    return Promise.reject(new Error(`unexpected GET ${url}`))
  })

  post.mockImplementation((url: string) =>
    url === '/tickets'
      ? Promise.resolve({ data: { data: { id: 'new-ticket-uuid' } } })
      : Promise.resolve({ data: { data: { id: 'attachment-uuid' } } }),
  )
})

afterEach(() => {
  window.matchMedia = originalMatchMedia
})

/** The router's path and state, and a Back button to prove the form left history. */
function Where() {
  const location = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <output data-testid="where">{location.pathname}</output>
      <output data-testid="state">{JSON.stringify(location.state)}</output>
      <button type="button" onClick={() => navigate(-1)}>
        browser back
      </button>
    </>
  )
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/tickets', '/app/tickets/new']} initialIndex={1}>
        <Routes>
          <Route path="/app/tickets/new" element={<CreateTicketPage />} />
          <Route path="/app/tickets/:id" element={<p>ticket page</p>} />
          <Route path="/app/tickets" element={<p>ticket list</p>} />
        </Routes>
        <Where />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const categorySelect = () =>
  screen.getByRole('combobox', { name: /what kind of problem is it/i }) as HTMLSelectElement

const groupsOf = (select: HTMLSelectElement) =>
  Array.from(select.querySelectorAll('optgroup')).map((group) => ({
    heading: group.getAttribute('label'),
    labels: Array.from(group.querySelectorAll('option')).map((option) => option.textContent),
  }))

const submitButton = () => screen.getByRole('button', { name: /report this problem/i })
const detailField = () => screen.queryByRole('textbox', { name: /describe the kind of problem/i })

async function ready() {
  await waitFor(() => expect(categorySelect().options).toHaveLength(15))
}

async function fill(category: string | null = 'power-issue') {
  await userEvent.type(screen.getByLabelText(/what is the problem/i), 'Projector will not start')
  await userEvent.type(
    screen.getByLabelText(/what happened/i),
    'It powers on, then shuts down after a few seconds.',
  )
  if (category) await userEvent.selectOptions(categorySelect(), category)
}

async function fillAndSubmit(category: string | null) {
  await fill(category)
  await userEvent.click(submitButton())
}

async function chooseRoom(name: RegExp) {
  const group = await screen.findByRole('radiogroup', { name: 'Which room?' })
  await userEvent.click(await within(group).findByRole('radio', { name }))
}

async function chooseItem(name: RegExp) {
  const group = await screen.findByRole('radiogroup', { name: 'Which item has the problem?' })
  await userEvent.click(await within(group).findByRole('radio', { name }))
}

const ticketPosts = () => post.mock.calls.filter(([url]) => url === '/tickets')
const payload = () => ticketPosts()[0][1] as Record<string, unknown>
const outcomeState = () => JSON.parse(screen.getByTestId('state').textContent ?? 'null')

/** Long enough for anything a keypress set off — a submit, a debounced save — to have happened. */
const settle = (ms = 150) => new Promise((resolve) => setTimeout(resolve, ms))

/** Answer one lookup differently, leaving the rest as `beforeEach` set them. */
function answerGet(url: string, data: unknown) {
  const base = get.getMockImplementation()!
  get.mockImplementation((called: string, config?: unknown) =>
    called === url ? ok(data) : base(called, config),
  )
}

function refusal(status: number, data: Record<string, unknown>) {
  return new AxiosError('Refused', String(status), undefined, undefined, {
    status,
    data,
  } as AxiosResponse)
}

describe('CreateTicketPage — category dropdown', () => {
  it('is one dropdown that opens on "Choose one…", then the grouped categories', async () => {
    renderPage()
    await ready()

    expect(categorySelect().tagName).toBe('SELECT')
    expect(categorySelect().options[0]).toHaveTextContent('Choose one…')
    expect(categorySelect().options[0].value).toBe('')
    expect(categorySelect().value).toBe('')
  })

  it('lists every category under its heading, in order, legacy Other included', async () => {
    renderPage()
    await ready()

    expect(groupsOf(categorySelect())).toEqual([
      {
        heading: 'Common Problems',
        labels: [
          'Hardware',
          'Software',
          'Network',
          'Performance & System Slowness',
          'Operating System Updates',
          'Power Issue',
          'Audio & Video Output',
          'Browser & Web Issues',
        ],
      },
      {
        heading: 'Advanced / Uncommon Problems',
        labels: [
          'Peripheral',
          'Account & Access',
          'BIOS / Firmware errors',
          'Blue Screen / Kernel Panic',
          'Missing Software',
        ],
      },
      { heading: 'Other Categories', labels: ['Other'] },
    ])
  })

  it('has no duplicate option values', async () => {
    renderPage()
    await ready()

    const values = Array.from(categorySelect().options).map((option) => option.value)
    expect(new Set(values).size).toBe(values.length)
  })

  it('submits the API slug of the chosen category, then opens the new ticket', async () => {
    renderPage()
    await ready()

    await fillAndSubmit('power-issue')

    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(post).toHaveBeenCalledWith(
      '/tickets',
      expect.objectContaining({ category: 'power-issue' }),
    )
    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
  })

  it('submits the one Other category, with its description', async () => {
    renderPage()
    await ready()

    await fill('other')
    await userEvent.type(detailField() as HTMLElement, 'Card reader')
    await userEvent.click(submitButton())

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith(
        '/tickets',
        expect.objectContaining({ category: 'other', category_detail: 'Card reader' }),
      ),
    )
  })

  it('does not submit until a category is chosen, and says why', async () => {
    renderPage()
    await ready()

    await fillAndSubmit(null)

    expect(await screen.findByText('Choose the kind of problem this is.')).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })
})

describe('CreateTicketPage — similar reports', () => {
  it('searches once the title has settled, not on every letter typed', async () => {
    renderPage()
    await ready()

    await userEvent.type(screen.getByLabelText(/what is the problem/i), 'Projector will not start')
    await waitFor(() => expect(duplicates.searches.at(-1)).toBe('Projector will not start'))

    // Every letter would be 25 different searches; settled, it is the empty
    // start and the finished title (with room for a pause mid-word on a slow run).
    expect(new Set(duplicates.searches).size).toBeLessThanOrEqual(3)
  })
})

describe('CreateTicketPage — the "Other" description', () => {
  it('appears only for a category that asks for it, and is required there', async () => {
    renderPage()
    await ready()

    await userEvent.selectOptions(categorySelect(), 'hardware')
    expect(detailField()).not.toBeInTheDocument()

    await userEvent.selectOptions(categorySelect(), 'other')
    expect(detailField()).toHaveAttribute('aria-required', 'true')
    expect(detailField()).toHaveAttribute('maxlength', '120')
  })

  it('stops the report until it is given', async () => {
    renderPage()
    await ready()

    await fillAndSubmit('other')

    expect(
      await screen.findByText(/say in a few words what kind of problem it is/i),
    ).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })

  it('is sent trimmed, and not at all for a category that does not ask for it', async () => {
    renderPage()
    await ready()

    await fill('other')
    await userEvent.type(detailField() as HTMLElement, '  Smartboard pen  ')
    await userEvent.selectOptions(categorySelect(), 'hardware')
    await userEvent.click(submitButton())

    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(payload()).not.toHaveProperty('category_detail')
  })
})

describe('CreateTicketPage — priority', () => {
  it('starts on "let the IT team decide", which sends no priority at all', async () => {
    renderPage()
    await ready()

    expect(screen.getByRole('radio', { name: 'Not sure — let the IT team decide' })).toBeChecked()
    await fillAndSubmit('power-issue')

    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(payload()).not.toHaveProperty('priority')
  })

  it('sends Urgent as its slug, critical', async () => {
    renderPage()
    await ready()

    await fill('power-issue')
    await userEvent.click(screen.getByRole('radio', { name: 'Urgent' }))
    await userEvent.click(submitButton())

    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(payload()).toMatchObject({ priority: 'critical' })
  })
})

describe('CreateTicketPage — where is it?', () => {
  it('sends the room and the PC, and no asset', async () => {
    renderPage()
    await ready()

    await fill('hardware')
    await chooseRoom(/lab a/i)
    await chooseItem(/pc 01/i)
    await userEvent.click(submitButton())

    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(payload()).toMatchObject({ room: LAB.id, pc_unit: PC, asset: null })
  })

  it('sends other equipment as an asset, and no PC', async () => {
    renderPage()
    await ready()

    await fill('hardware')
    await chooseRoom(/lab a/i)
    await chooseItem(/epson projector/i)
    await userEvent.click(submitButton())

    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(payload()).toMatchObject({ room: LAB.id, pc_unit: null, asset: PROJECTOR })
  })

  it('forgets the item when the room changes', async () => {
    renderPage()
    await ready()

    await fill('hardware')
    await chooseRoom(/lab a/i)
    await chooseItem(/pc 01/i)
    await chooseRoom(/office room/i)
    await userEvent.click(submitButton())

    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(payload()).toMatchObject({ room: OFFICE.id, pc_unit: null, asset: null })
  })

  it('shows what is already reported on a chosen PC', async () => {
    renderPage()
    await ready()

    await chooseRoom(/lab a/i)
    await chooseItem(/pc 01/i)

    expect(await screen.findByText(`already reported on ${PC}`)).toBeInTheDocument()
  })

  it('shows a refusal of the item above the item list, and puts focus on the refused item', async () => {
    post.mockImplementation((url: string) =>
      url === '/tickets'
        ? Promise.reject(
            refusal(422, {
              message: 'That item is not in the room you chose.',
              errors: { asset: ['That item is not in the room you chose.'] },
            }),
          )
        : Promise.resolve({ data: { data: {} } }),
    )
    renderPage()
    await ready()

    await fill('hardware')
    await chooseRoom(/lab a/i)
    await chooseItem(/epson projector/i)
    await userEvent.click(submitButton())

    const group = screen.getByRole('radiogroup', { name: 'Which item has the problem?' })
    const message = await within(group).findByRole('alert')
    expect(message).toHaveTextContent('That item is not in the room you chose.')
    expect(group).toHaveAttribute('aria-describedby', message.id)
    expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new')

    // Focus lands on the refused row — not on "Not about a specific item" at the top.
    const projector = within(group).getByRole('radio', { name: /epson projector/i })
    await waitFor(() => expect(projector).toHaveFocus())
    // And the message is read before the list, not after it.
    expect(message.compareDocumentPosition(projector) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    )
  })

  it('puts focus on a refused room, wherever it is in the list', async () => {
    post.mockImplementation(() =>
      Promise.reject(
        refusal(422, {
          message: 'That room is no longer in use.',
          errors: { room: ['That room is no longer in use.'] },
        }),
      ),
    )
    renderPage()
    await ready()

    await fill('hardware')
    await chooseRoom(/office room/i)
    await userEvent.click(submitButton())

    const group = screen.getByRole('radiogroup', { name: 'Which room?' })
    expect(await within(group).findByRole('alert')).toHaveTextContent(
      'That room is no longer in use.',
    )
    await waitFor(() =>
      expect(within(group).getByRole('radio', { name: /office room/i })).toHaveFocus(),
    )
  })

  it('names each item once — its label already ends with the tag', async () => {
    renderPage()
    await ready()

    await chooseRoom(/lab a/i)
    const group = await screen.findByRole('radiogroup', { name: 'Which item has the problem?' })
    await within(group).findByRole('radio', { name: /pc 01/i })

    expect(within(group).getAllByText(/PC-LA-01/)).toHaveLength(1)
    expect(within(group).getAllByText(/AST-0001/)).toHaveLength(1)
  })
})

describe('CreateTicketPage — Enter in a filter box', () => {
  const classrooms = Array.from({ length: 9 }, (_, index) => ({
    ...OFFICE,
    id: `room-${index + 1}`,
    name: `Classroom ${index + 1}`,
    code: `CR-${index + 1}`,
    room_type: 'classroom',
    room_type_label: 'Classroom',
  }))

  it('does not send the report from "Find a room" — the phone’s Search key included', async () => {
    answerGet('/lookups/rooms', [LAB, ...classrooms])
    renderPage()
    await ready()
    await fill('hardware')

    const search = await screen.findByRole('searchbox', { name: 'Find a room' })
    expect(search).toHaveAttribute('enterkeyhint', 'search')
    await userEvent.type(search, 'lab{Enter}')
    await settle()

    expect(ticketPosts()).toHaveLength(0)
    // The filter still did its job, and the reporter can carry on choosing.
    expect(
      within(screen.getByRole('radiogroup', { name: 'Which room?' })).getAllByRole('radio'),
    ).toHaveLength(2)
  })

  it('does not send the report from "Find an item"', async () => {
    answerGet('/lookups/equipment', {
      pc_units: Array.from({ length: 9 }, (_, index) => ({
        id: `${index + 1}`.padStart(8, '0') + '-1111-4111-8111-111111111111',
        label: `PC ${index + 1} (PC-LA-0${index + 1})`,
        identifier: `PC-LA-0${index + 1}`,
        location: null,
      })),
      assets: [],
    })
    renderPage()
    await ready()
    await fill('hardware')
    await chooseRoom(/lab a/i)

    const search = await screen.findByRole('searchbox', { name: 'Find an item' })
    expect(search).toHaveAttribute('enterkeyhint', 'search')
    await userEvent.type(search, 'PC 3{Enter}')
    await settle()

    expect(ticketPosts()).toHaveLength(0)
  })
})

describe('CreateTicketPage — photos and sending', () => {
  const photo = (name: string) => new File(['bytes'], name, { type: 'image/jpeg' })

  async function addPhotos(...names: string[]) {
    await userEvent.upload(
      screen.getByTestId('photo-gallery-input'),
      names.map((name) => photo(name)),
    )
    await waitFor(() => expect(screen.getAllByRole('img')).toHaveLength(names.length))
  }

  it('creates the ticket, then attaches each photo in turn, then opens the ticket with the outcome', async () => {
    renderPage()
    await ready()

    await fill('hardware')
    await addPhotos('a.jpg', 'b.jpg')
    await userEvent.click(submitButton())

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    expect(post.mock.calls.map(([url]) => url)).toEqual([
      '/tickets',
      '/tickets/new-ticket-uuid/attachments',
      '/tickets/new-ticket-uuid/attachments',
    ])
    expect((post.mock.calls[1][1] as FormData).get('file')).toHaveProperty('name', 'a.jpg')
    expect((post.mock.calls[2][1] as FormData).get('file')).toHaveProperty('name', 'b.jpg')
    expect(JSON.parse(screen.getByTestId('state').textContent ?? 'null')).toEqual({
      reportOutcome: { ticketId: 'new-ticket-uuid', attached: 2, failed: [] },
    })
  })

  it('replaces the form in history, so Back does not return to a blank report', async () => {
    renderPage()
    await ready()

    await fillAndSubmit('hardware')
    await waitFor(() => expect(screen.getByText('ticket page')).toBeInTheDocument())

    await userEvent.click(screen.getByRole('button', { name: 'browser back' }))
    expect(await screen.findByText('ticket list')).toBeInTheDocument()
  })

  it('still opens the ticket when a photo fails, saying which one', async () => {
    let upload = 0
    post.mockImplementation((url: string) => {
      if (url === '/tickets') return Promise.resolve({ data: { data: { id: 'new-ticket-uuid' } } })
      upload += 1
      return upload === 2
        ? Promise.reject(
            new AxiosError('Unprocessable', '422', undefined, undefined, {
              status: 422,
              data: { message: 'The file field must not be greater than 10240 kilobytes.' },
            } as AxiosResponse),
          )
        : Promise.resolve({ data: { data: {} } })
    })
    renderPage()
    await ready()

    await fill('hardware')
    await addPhotos('a.jpg', 'image.jpg', 'c.jpg')
    await userEvent.click(submitButton())

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    expect(JSON.parse(screen.getByTestId('state').textContent ?? 'null')).toEqual({
      reportOutcome: {
        ticketId: 'new-ticket-uuid',
        attached: 2,
        failed: [
          {
            index: 2,
            name: 'image.jpg',
            message: 'The file field must not be greater than 10240 kilobytes.',
          },
        ],
      },
    })
  })

  it('names a failed photo as the form listed it — refused photos counted, the name as picked', async () => {
    // Photo 1 is refused while it is prepared; photo 3 is redrawn — and so
    // renamed — and then its upload fails.
    vi.mocked(preparePhoto).mockImplementation(async (file) => {
      if (file.name === 'refused.jpg') throw new UnsupportedPhotoError()
      if (file.name === 'Screenshot.png') {
        return { file: new File(['x'], 'Screenshot.jpg', { type: 'image/jpeg' }), reencoded: true }
      }
      return { file, reencoded: false }
    })
    post.mockImplementation((url: string, body: FormData) => {
      if (url === '/tickets') return Promise.resolve({ data: { data: { id: 'new-ticket-uuid' } } })
      return (body.get('file') as File).name === 'Screenshot.jpg'
        ? Promise.reject(refusal(422, { message: 'The file could not be stored.' }))
        : Promise.resolve({ data: { data: {} } })
    })
    renderPage()
    await ready()

    await fill('hardware')
    await userEvent.upload(screen.getByTestId('photo-gallery-input'), [
      photo('refused.jpg'),
      photo('b.jpg'),
      new File(['png'], 'Screenshot.png', { type: 'image/png' }),
    ])
    await waitFor(() => expect(screen.getAllByRole('img')).toHaveLength(2))
    await userEvent.click(submitButton())

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    expect(outcomeState()).toEqual({
      reportOutcome: {
        ticketId: 'new-ticket-uuid',
        attached: 1,
        // Photo 1's refusal was on screen before sending, so it is not repeated.
        failed: [{ index: 3, name: 'Screenshot.png', message: 'The file could not be stored.' }],
      },
    })
  })

  it('still sends the report when a photo is refused while it waits, and lists that photo', async () => {
    let refuse: () => void = () => {}
    vi.mocked(preparePhoto).mockImplementation((file) =>
      file.name === 'slow.jpg'
        ? new Promise((_, reject) => {
            refuse = () => reject(new UnsupportedPhotoError())
          })
        : Promise.resolve({ file, reencoded: false }),
    )
    renderPage()
    await ready()

    await fill('hardware')
    await userEvent.upload(screen.getByTestId('photo-gallery-input'), [
      photo('a.jpg'),
      photo('slow.jpg'),
    ])
    await screen.findByRole('img', { name: 'Photo 1: a.jpg' })
    await userEvent.click(submitButton())
    expect(await screen.findByText('Preparing photos…')).toBeInTheDocument()

    refuse()

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    expect(outcomeState().reportOutcome).toEqual({
      ticketId: 'new-ticket-uuid',
      attached: 1,
      failed: [
        { index: 2, name: 'slow.jpg', message: expect.stringMatching(/format cannot be used/i) },
      ],
    })
  })

  it('stops sending photos at the hourly upload limit, marking the rest as not sent', async () => {
    post.mockImplementation((url: string) =>
      url === '/tickets'
        ? Promise.resolve({ data: { data: { id: 'new-ticket-uuid' } } })
        : Promise.reject(refusal(429, { message: 'Too Many Attempts.' })),
    )
    renderPage()
    await ready()

    await fill('hardware')
    await addPhotos('a.jpg', 'b.jpg', 'c.jpg')
    await userEvent.click(submitButton())

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    // One photo went up and was refused; the other two were never sent.
    expect(post.mock.calls.filter(([url]) => url !== '/tickets')).toHaveLength(1)
    expect(
      outcomeState().reportOutcome.failed.map((failure: { message: string }) => failure.message),
    ).toEqual([
      'Too many photos were sent in the last hour — try again later.',
      'Not sent — too many photos were sent in the last hour; add it again later under Photos & files.',
      'Not sent — too many photos were sent in the last hour; add it again later under Photos & files.',
    ])
  })

  it('stops sending photos once the session is gone, marking the rest as not sent', async () => {
    post.mockImplementation((url: string) =>
      url === '/tickets'
        ? Promise.resolve({ data: { data: { id: 'new-ticket-uuid' } } })
        : Promise.reject(
            new AxiosError('Unauthorized', '401', undefined, undefined, {
              status: 401,
              data: { message: 'Unauthenticated.' },
            } as AxiosResponse),
          ),
    )
    renderPage()
    await ready()

    await fill('hardware')
    await addPhotos('a.jpg', 'b.jpg', 'c.jpg')
    await userEvent.click(submitButton())

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    // One attempt, then nothing more is sent.
    expect(post.mock.calls.filter(([url]) => url !== '/tickets')).toHaveLength(1)
    const state = JSON.parse(screen.getByTestId('state').textContent ?? 'null')
    expect(state.reportOutcome.attached).toBe(0)
    expect(state.reportOutcome.failed.map((failure: { index: number }) => failure.index)).toEqual([
      1, 2, 3,
    ])
  })

  it('says what is happening while it sends', async () => {
    let finishUpload: () => void = () => {}
    post.mockImplementation((url: string) =>
      url === '/tickets'
        ? Promise.resolve({ data: { data: { id: 'new-ticket-uuid' } } })
        : new Promise((resolve) => {
            finishUpload = () => resolve({ data: { data: {} } })
          }),
    )
    renderPage()
    await ready()

    await fill('hardware')
    await addPhotos('a.jpg')
    await userEvent.click(submitButton())

    const status = await screen.findByText('Attaching photo 1 of 1…')
    expect(status).toHaveAttribute('role', 'status')
    // Everything is locked while it sends.
    expect(submitButton()).toBeDisabled()
    expect(screen.getByLabelText(/what is the problem/i)).toBeDisabled()

    finishUpload()
    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
  })

  it('files exactly one report however often it is submitted while sending — Enter included', async () => {
    let finishCreate: () => void = () => {}
    post.mockImplementation((url: string) =>
      url === '/tickets'
        ? new Promise((resolve) => {
            finishCreate = () => resolve({ data: { data: { id: 'new-ticket-uuid' } } })
          })
        : Promise.resolve({ data: { data: {} } }),
    )
    renderPage()
    await ready()
    await fill('hardware')

    const title = screen.getByLabelText(/what is the problem/i)
    const form = submitButton().closest('form') as HTMLFormElement

    // Two submits in the same tick — a double tap — then Enter in a field and
    // another click while the first report is still being created.
    fireEvent.submit(form)
    fireEvent.submit(form)
    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    await userEvent.type(title, '{Enter}')
    fireEvent.submit(form)
    await userEvent.click(submitButton())

    expect(ticketPosts()).toHaveLength(1)

    finishCreate()
    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    expect(ticketPosts()).toHaveLength(1)
  })

  it('keeps everything and says so when the report could not be sent; no photo is uploaded', async () => {
    post.mockImplementation((url: string) =>
      url === '/tickets'
        ? Promise.reject(
            new AxiosError('Server Error', '500', undefined, undefined, {
              status: 500,
              data: { message: 'Server Error' },
            } as AxiosResponse),
          )
        : Promise.resolve({ data: { data: {} } }),
    )
    renderPage()
    await ready()

    await fill('hardware')
    await addPhotos('a.jpg')
    await userEvent.click(submitButton())

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Your report was not sent')
    expect(alert).toHaveTextContent('Server Error')
    expect(post.mock.calls.filter(([url]) => url !== '/tickets')).toHaveLength(0)
    expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new')
    expect(screen.getByLabelText(/what is the problem/i)).toHaveValue('Projector will not start')
    await waitFor(() => expect(submitButton()).toHaveFocus())
  })
})

describe('CreateTicketPage — the unsent-report draft', () => {
  it('offers back what was typed before the tab was lost, and says photos need adding again', async () => {
    saveReportDraft('user-1', {
      title: 'Projector will not start',
      description: 'It powers on, then shuts down.',
      category: 'other',
      category_detail: 'Smartboard pen',
      priority: 'high',
      building: 'b-1',
      room: LAB.id,
      item: '',
    })
    renderPage()
    await ready()

    expect(screen.getByLabelText(/what is the problem/i)).toHaveValue('Projector will not start')
    expect(categorySelect()).toHaveValue('other')
    expect(detailField()).toHaveValue('Smartboard pen')
    expect(screen.getByRole('radio', { name: 'High' })).toBeChecked()
    const lab = await within(
      await screen.findByRole('radiogroup', { name: 'Which room?' }),
    ).findByRole('radio', { name: /lab a/i })
    expect(lab).toBeChecked()

    const note = screen
      .getByText('Restored your unsent report')
      .closest('[role="status"]') as HTMLElement
    expect(note).toHaveTextContent(/photos need adding again/i)
    await userEvent.click(within(note).getByRole('button', { name: 'Dismiss' }))
    expect(screen.queryByText('Restored your unsent report')).not.toBeInTheDocument()
    // The button went with the note; focus goes to where the report starts.
    await waitFor(() => expect(screen.getByLabelText(/what is the problem/i)).toHaveFocus())
  })

  it('does not keep an untouched form — the building chosen for the reporter is not a draft', async () => {
    // A first visit: nothing cached, so the only building is chosen once its
    // lookup answers, after the form is already watching for changes.
    const first = renderPage()
    await ready()
    await screen.findByRole('radiogroup', { name: 'Which room?' })
    await settle(600) // past the draft's 400 ms save delay

    expect(readReportDraft('user-1')).toBeNull()
    first.unmount()

    renderPage()
    await ready()
    expect(screen.queryByText('Restored your unsent report')).not.toBeInTheDocument()
  })

  it('lets go of a restored category or priority that is no longer offered', async () => {
    saveReportDraft('user-1', {
      title: 'Projector will not start',
      description: 'It powers on, then shuts down after a few seconds.',
      category: 'retired-category',
      category_detail: 'Smartboard pen',
      priority: 'retired-priority',
      building: '',
      room: '',
      item: '',
    })
    renderPage()
    await ready()

    // Nothing checked would otherwise show — not even "Not sure".
    await waitFor(() =>
      expect(
        screen.getByRole('radio', { name: 'Not sure — let the IT team decide' }),
      ).toBeChecked(),
    )
    // The old category is not sent behind a select that reads "Choose one…".
    await userEvent.click(submitButton())
    expect(await screen.findByText('Choose the kind of problem this is.')).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()

    await userEvent.selectOptions(categorySelect(), 'hardware')
    await userEvent.click(submitButton())
    await waitFor(() => expect(ticketPosts()).toHaveLength(1))
    expect(payload()).toMatchObject({ category: 'hardware' })
    expect(payload()).not.toHaveProperty('priority')
    expect(payload()).not.toHaveProperty('category_detail')
  })

  it('is kept when the reporter only steps away by the back link', async () => {
    const draft = {
      title: 'Projector will not start',
      description: '',
      category: '',
      category_detail: '',
      priority: '',
      building: '',
      room: '',
      item: '',
    }
    saveReportDraft('user-1', draft)
    renderPage()
    await ready()

    await userEvent.click(screen.getByRole('button', { name: /all tickets/i }))
    expect(await screen.findByText('ticket list')).toBeInTheDocument()
    expect(readReportDraft('user-1')).toEqual(draft)
  })

  it('is gone after Cancel', async () => {
    saveReportDraft('user-1', {
      title: 'Projector will not start',
      description: '',
      category: '',
      category_detail: '',
      priority: '',
      building: '',
      room: '',
      item: '',
    })
    renderPage()
    await ready()

    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(await screen.findByText('ticket list')).toBeInTheDocument()
    expect(readReportDraft('user-1')).toBeNull()
  })

  it('is forgotten once the report is filed', async () => {
    saveReportDraft('user-1', {
      title: 'Projector will not start',
      description: 'It powers on, then shuts down after a few seconds.',
      category: 'hardware',
      category_detail: '',
      priority: '',
      building: '',
      room: '',
      item: '',
    })
    renderPage()
    await ready()

    await userEvent.click(submitButton())

    await waitFor(() =>
      expect(screen.getByTestId('where')).toHaveTextContent('/app/tickets/new-ticket-uuid'),
    )
    expect(readReportDraft('user-1')).toBeNull()
  })
})
