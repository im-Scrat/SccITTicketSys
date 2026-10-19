import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { API_CATEGORIES } from '../categoryFixtures'
import AssignedTicketsPage from './AssignedTicketsPage'

const get = vi.fn()

vi.mock('@/services/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}))

function Search() {
  return <output data-testid="search">{useLocation().search}</output>
}

function renderPage(initialEntry = '/app/tickets/assigned') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <AssignedTicketsPage />
        <Search />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const ROW = {
  id: 'tkt-1',
  ticket_number: 'TKT-1',
  title: 'Projector will not start',
  status: { slug: 'in-progress', label: 'In Progress', color: '#8b5cf6', is_open: true },
  priority: { slug: 'high', label: 'High', level: 3 },
  category: { slug: 'hardware', label: 'Hardware', detail: null },
  reporter: { name: 'Ada Teacher' },
  technician: null,
  pc_unit: null,
  asset: null,
  location: { room: 'Lab 3', building: 'Main' },
  upvote_count: 0,
  comment_count: 0,
  attachment_count: 0,
  resolution_due_at: null,
  sla: null,
  created_at: null,
  updated_at: null,
  archived: false,
}

let rows: unknown[] = []

const queueRequests = () =>
  get.mock.calls
    .filter(([url]) => url === '/tickets/assigned')
    .map(([, config]) => (config as { params: Record<string, unknown> }).params)

const urlParams = () => new URLSearchParams(screen.getByTestId('search').textContent ?? '')

beforeEach(() => {
  rows = []
  get.mockReset()
  get.mockImplementation((url: string) =>
    Promise.resolve(
      url === '/tickets/options'
        ? {
            data: {
              data: {
                categories: API_CATEGORIES,
                statuses: [],
                tags: [],
                priorities: [],
                technicians: [],
              },
            },
          }
        : {
            data: {
              data: rows,
              meta: {
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: rows.length,
                from: rows.length ? 1 : null,
                to: rows.length || null,
              },
            },
          },
    ),
  )
})

describe('AssignedTicketsPage — the dashboard views', () => {
  it('sends the plain active queue when there is no view', async () => {
    renderPage()

    await waitFor(() => expect(queueRequests()).toHaveLength(1))
    const sent = queueRequests()[0]
    expect(sent).not.toHaveProperty('scope')
    expect(sent).not.toHaveProperty('breached')
    expect(sent).not.toHaveProperty('due_soon')
    expect(screen.getByRole('button', { name: 'Active assignments' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  it.each([
    ['overdue', { scope: 'assigned', breached: 1 }],
    ['due-soon', { scope: 'assigned', due_soon: 1 }],
    ['open', { scope: 'assigned', status: 'open,assigned,in-progress,on-hold,resolved' }],
  ])('?view=%s sends the parameters its dashboard figure counts', async (view, expected) => {
    renderPage(`/app/tickets/assigned?view=${view}`)

    await waitFor(() => expect(queueRequests().length).toBeGreaterThan(0))
    expect(queueRequests().at(-1)).toMatchObject(expected)
  })

  it('marks the active view pressed and describes it', async () => {
    renderPage('/app/tickets/assigned?view=overdue')

    expect(screen.getByRole('button', { name: 'Overdue' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByText(/missed a response or fix-by deadline/i)).toBeInTheDocument()
  })

  it('switches view from the toggle and writes it to the URL', async () => {
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: 'Due within 4 h' }))

    await waitFor(() => expect(urlParams().get('view')).toBe('due-soon'))
    await waitFor(() => expect(queueRequests().at(-1)).toMatchObject({ due_soon: 1 }))
  })

  it('treats an unknown view as the active queue', async () => {
    renderPage('/app/tickets/assigned?view=bogus')

    await waitFor(() => expect(queueRequests()).toHaveLength(1))
    expect(queueRequests()[0]).not.toHaveProperty('scope')
  })

  it('does not offer a Status filter on the Open view — the view is the status', async () => {
    renderPage('/app/tickets/assigned?view=open&status=closed')

    expect(screen.queryByRole('combobox', { name: /^status/i })).toBeNull()
    await waitFor(() => expect(queueRequests().length).toBeGreaterThan(0))
    expect(queueRequests().at(-1)?.status).toBe('open,assigned,in-progress,on-hold,resolved')
  })

  it('says what an empty view means and offers the way back', async () => {
    renderPage('/app/tickets/assigned?view=overdue')

    expect(await screen.findByText('Nothing is overdue')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Show my active assignments' }))
    await waitFor(() => expect(urlParams().get('view')).toBeNull())
  })
})

describe('AssignedTicketsPage — rows', () => {
  it('opens a ticket from a real link, so keyboard and middle-click work', async () => {
    rows = [ROW]
    renderPage()

    const link = await screen.findByRole('link', { name: 'Projector will not start' })
    expect(link).toHaveAttribute('href', '/app/tickets/assigned/tkt-1')
  })
})
