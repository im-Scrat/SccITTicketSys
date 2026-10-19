import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { API_CATEGORIES } from '../categoryFixtures'
import TicketManagementPage from './TicketManagementPage'

const get = vi.fn()

vi.mock('@/services/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}))

const PAGINATED = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null },
}

const DASHBOARD = {
  summary: {
    open_backlog: 9,
    unassigned: 1,
    breached: 3,
    at_risk: 2,
    lead_hours: 4,
    awaiting_confirmation: 2,
  },
  by_status: [],
  by_priority: [],
  technician_workload: [],
  triage_queue: [],
  awaiting_confirmation: [],
  auto_close_days: 14,
  generated_at: '2026-10-17T10:00:00+08:00',
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/tickets/manage']}>
        <TicketManagementPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const directoryRequests = () =>
  get.mock.calls
    .filter(([url]) => url === '/admin/tickets')
    .map(([, config]) => (config as { params: Record<string, unknown> }).params)

let dashboard: typeof DASHBOARD = DASHBOARD
let statuses: Array<{ value: string; label: string; is_open: boolean }> = []

beforeEach(() => {
  dashboard = DASHBOARD
  statuses = []
  get.mockReset()
  get.mockImplementation((url: string) => {
    if (url === '/admin/tickets/dashboard') return Promise.resolve({ data: { data: dashboard } })
    if (url === '/tickets/options') {
      return Promise.resolve({
        data: {
          data: {
            categories: API_CATEGORIES,
            statuses,
            tags: [],
            priorities: [],
            technicians: [],
          },
        },
      })
    }
    return Promise.resolve({ data: PAGINATED })
  })
})

/*
 * Laravel's `boolean` rule accepts `1` but not the string "true" that axios sends
 * for a JS `true`, so these tiles used to answer with a 422. The backend test
 * (TicketManagementOverdueParityTest) asserts the 422; this asserts the page no
 * longer provokes it.
 */
describe('TicketManagementPage — boolean tile filters', () => {
  it('sends breached as 1 when the Overdue tile is pressed', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /^Overdue/ }))

    await waitFor(() => expect(directoryRequests().length).toBeGreaterThan(0))
    const sent = directoryRequests().at(-1)
    expect(sent?.breached).toBe(1)
    expect(sent?.breached).not.toBe(true)
  })

  it('sends awaiting_confirmation as 1 when its tile is pressed', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: /^Awaiting confirmation/ }))

    await waitFor(() => expect(directoryRequests().length).toBeGreaterThan(0))
    expect(directoryRequests().at(-1)?.awaiting_confirmation).toBe(1)
  })

  it('describes Overdue as a missed response or fix-by deadline', async () => {
    renderPage()

    expect(await screen.findByText('Missed a response or fix-by deadline')).toBeInTheDocument()
    expect(screen.queryByText('Past the resolution deadline')).toBeNull()
  })
})

describe('TicketManagementPage — header and workload', () => {
  it('offers no Import or Export buttons', async () => {
    renderPage()

    await screen.findByRole('heading', { name: 'Ticket management' })
    expect(screen.queryByRole('button', { name: /import/i })).toBeNull()
    expect(screen.queryByRole('button', { name: /export/i })).toBeNull()
  })

  it('links each "Who is carrying what" row to that technician’s open tickets', async () => {
    statuses = [
      { value: 'new', label: 'New', is_open: true },
      { value: 'in_progress', label: 'In progress', is_open: true },
      { value: 'closed', label: 'Closed', is_open: false },
    ]
    dashboard = {
      ...DASHBOARD,
      technician_workload: [{ id: 'tech-1', name: 'Ana Cruz', count: 4 }],
    } as unknown as typeof DASHBOARD
    renderPage()

    const link = await screen.findByRole('link', { name: /Ana Cruz: 4 open/ })
    await waitFor(() => expect(link.getAttribute('href')).toContain('status='))
    const url = new URL(link.getAttribute('href')!, 'http://x')
    expect(url.pathname).toBe('/app/tickets/manage')
    expect(url.searchParams.get('tab')).toBe('directory')
    expect(url.searchParams.get('technician')).toBe('tech-1')
    expect(url.searchParams.get('status')).toBe('new,in_progress')

    // Following it opens the directory filtered to exactly those tickets.
    await userEvent.click(link)
    await waitFor(() =>
      expect(directoryRequests().at(-1)).toMatchObject({
        technician: 'tech-1',
        status: 'new,in_progress',
      }),
    )
  })
})
