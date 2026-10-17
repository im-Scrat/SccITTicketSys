import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { API_CATEGORIES } from '../categoryFixtures'
import TicketFeedPage from './TicketFeedPage'

/**
 * The feed's priority filter is an administrator's tool. It used to be shown
 * whenever the options carried priorities — a stand-in for "is staff" that
 * stopped being true when every role started receiving them (SDD DD-75).
 */

const get = vi.fn()
let role = 'teacher'

vi.mock('@/services/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({
    hasPermission: () => true,
    hasRole: (candidate: string) => candidate === role,
  }),
}))

/** What a requester now receives: priorities included, without SLA minutes. */
const OPTIONS = {
  categories: API_CATEGORIES,
  statuses: [{ value: 'open', label: 'Open', color: '#2563eb', is_open: true, is_terminal: false }],
  tags: [],
  priorities: [
    { value: 'critical', label: 'Urgent', level: 4, color: '#ef4444' },
    { value: 'low', label: 'Low', level: 1, color: '#64748b' },
  ],
  technicians: [],
  uploads: { max_kb: 10240 },
}

beforeEach(() => {
  get.mockReset()
  get.mockImplementation((url: string) => {
    if (url === '/tickets/options') return Promise.resolve({ data: { data: OPTIONS } })
    if (url === '/tickets/feed') {
      return Promise.resolve({
        data: {
          data: [],
          meta: { path: '/tickets/feed', per_page: 20, next_cursor: null, prev_cursor: null },
          links: { first: null, last: null, prev: null, next: null },
        },
      })
    }
    return Promise.reject(new Error(`unexpected GET ${url}`))
  })
})

function renderFeed() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/tickets']}>
        <Routes>
          <Route path="/app/tickets" element={<TicketFeedPage />} />
          <Route path="/app/tickets/assigned" element={<p>assigned queue</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('TicketFeedPage — the priority filter', () => {
  it('is not offered to a teacher, although their options now carry priorities', async () => {
    role = 'teacher'
    renderFeed()

    // The options have arrived: the category list is filled from them.
    await screen.findByRole('option', { name: 'Hardware' })
    expect(screen.queryByRole('combobox', { name: /^priority/i })).not.toBeInTheDocument()
  })

  it('is offered to an administrator', async () => {
    role = 'administrator'
    renderFeed()

    expect(await screen.findByRole('combobox', { name: /^priority/i })).toBeInTheDocument()
  })

  it('sends a technician to their own queue instead', async () => {
    role = 'technician'
    renderFeed()

    expect(await screen.findByText('assigned queue')).toBeInTheDocument()
  })
})
