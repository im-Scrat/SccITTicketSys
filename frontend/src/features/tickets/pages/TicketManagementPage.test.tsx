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

beforeEach(() => {
  get.mockReset()
  get.mockImplementation((url: string) => {
    if (url === '/admin/tickets/dashboard') return Promise.resolve({ data: { data: DASHBOARD } })
    if (url === '/tickets/options') {
      return Promise.resolve({
        data: {
          data: {
            categories: API_CATEGORIES,
            statuses: [],
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
