import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReportOutcome } from '../reportOutcome'
import type { TicketDetail, TicketDetailEnvelope } from '../types'
import TicketDetailPage from './TicketDetailPage'

/**
 * Arriving on a new ticket from the report form (SDD DD-80): the one-time
 * notice about the report's photos — announced by taking focus, tied to its own
 * ticket, removed from the history entry once read.
 */

const get = vi.fn()

vi.mock('@/services/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({
    hasPermission: () => true,
    hasRole: (role: string) => role === 'teacher',
  }),
}))

function ticket(id: string): TicketDetail {
  return {
    id,
    ticket_number: id === 'ticket-1' ? 'TKT-0001' : 'TKT-0002',
    title: 'Projector shows no picture',
    description: 'Lab 2 projector says “No signal”.',
    source: 'web',
    status: { slug: 'open', label: 'Open', color: 'blue', is_open: true, is_terminal: false },
    priority: { slug: 'medium', label: 'Medium', color: 'amber', level: 2 },
    category: { slug: 'hardware', label: 'Hardware', detail: null },
    reporter: { id: 'user-1', name: 'A. Teacher' },
    technician: null,
    is_assigned: false,
    pc_unit: null,
    asset: null,
    location: null,
    upvote_count: 0,
    comment_count: 0,
    attachment_count: 1,
    has_voted: false,
    duplicate_of: null,
    reported_at: '2026-10-16T08:00:00+08:00',
    first_response_at: null,
    resolved_at: null,
    closed_at: null,
    reopened_at: null,
    sla: null,
    ai: null,
    archived: false,
    archived_at: null,
  }
}

function envelope(id: string): TicketDetailEnvelope {
  return { data: ticket(id), meta: { transitions: [], can: undefined } }
}

beforeEach(() => {
  get.mockReset()
  get.mockImplementation((url: string) => {
    if (url.endsWith('/ai-analysis')) {
      return Promise.resolve({ data: { data: null, meta: { available: false } } })
    }
    if (url.endsWith('/attachments')) return Promise.resolve({ data: { data: [] } })
    if (url === '/tickets/options') {
      return Promise.resolve({
        data: { data: { categories: [], statuses: [], tags: [], priorities: [], technicians: [] } },
      })
    }
    const id = url.split('/').pop() as string
    return Promise.resolve({ data: envelope(id) })
  })
})

/** Shows the history entry's state, and a way to open another ticket in the same page instance. */
function Probe() {
  const location = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <output data-testid="state">{JSON.stringify(location.state)}</output>
      <output data-testid="url">{`${location.pathname}${location.search}${location.hash}`}</output>
      <button type="button" onClick={() => navigate('/app/tickets/ticket-2')}>
        open ticket 2
      </button>
      <button type="button" onClick={() => navigate('/app/tickets/ticket-1')}>
        open ticket 1
      </button>
    </>
  )
}

function renderPage(state: unknown, path = '/app/tickets/ticket-1') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter
        initialEntries={[
          {
            pathname: path.split('?')[0],
            search: path.includes('?') ? `?${path.split('?')[1]}` : '',
            state,
          },
        ]}
      >
        <Routes>
          <Route path="/app/tickets/:id" element={<TicketDetailPage />} />
        </Routes>
        <Probe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const outcome = (overrides: Partial<ReportOutcome> = {}): ReportOutcome => ({
  ticketId: 'ticket-1',
  attached: 1,
  failed: [
    {
      index: 2,
      name: 'image.jpg',
      message: 'Too many photos were sent in the last hour — try again later.',
    },
  ],
  ...overrides,
})

describe('TicketDetailPage — the report-outcome notice', () => {
  it('names each photo that failed, by position and name, with the reason', async () => {
    renderPage({ reportOutcome: outcome() })

    const heading = await screen.findByRole('heading', {
      name: 'Your report was sent, but 1 photo could not be attached',
    })
    const notice = heading.closest('section') as HTMLElement
    expect(within(notice).getByText('Photo 2 (image.jpg)')).toBeInTheDocument()
    expect(notice).toHaveTextContent(/too many photos were sent in the last hour/i)
    expect(notice).toHaveTextContent(/the other 1 photo arrived/i)
    expect(notice).toHaveTextContent(/add the missing ones again under photos & files/i)
  })

  it('takes focus, so a screen reader announces it', async () => {
    renderPage({ reportOutcome: outcome() })

    const heading = await screen.findByRole('heading', { name: /your report was sent/i })
    await waitFor(() => expect(heading).toHaveFocus())
    expect(heading).toHaveAttribute('tabindex', '-1')
  })

  it('opens "Photos & files" when a photo did not make it', async () => {
    renderPage({ reportOutcome: outcome() })

    const tab = await screen.findByRole('tab', { name: /photos & files/i })
    expect(tab).toHaveAttribute('aria-selected', 'true')
  })

  it('confirms quietly when every photo arrived, and stays on Details', async () => {
    renderPage({ reportOutcome: outcome({ attached: 2, failed: [] }) })

    expect(
      await screen.findByRole('heading', { name: 'Your report was sent with 2 photos' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /details/i })).toHaveAttribute('aria-selected', 'true')
  })

  it('is removed from the history entry once read, keeping the address as it was', async () => {
    renderPage({ reportOutcome: outcome() }, '/app/tickets/ticket-1?from=report')

    await screen.findByRole('heading', { name: /your report was sent/i })
    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('null'))
    expect(screen.getByTestId('url')).toHaveTextContent('/app/tickets/ticket-1?from=report')
    // Read once is enough: clearing the entry does not take the notice away.
    expect(screen.getByRole('heading', { name: /your report was sent/i })).toBeInTheDocument()
  })

  it('shows nothing when the page was not reached from the report form', async () => {
    renderPage(null)

    await screen.findByRole('heading', { name: 'Projector shows no picture' })
    expect(screen.queryByRole('heading', { name: /your report was sent/i })).not.toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /details/i })).toHaveAttribute('aria-selected', 'true')
  })

  it('ignores an outcome that belongs to another ticket', async () => {
    renderPage({ reportOutcome: outcome({ ticketId: 'ticket-9' }) })

    await screen.findByRole('heading', { name: 'Projector shows no picture' })
    expect(screen.queryByRole('heading', { name: /your report was sent/i })).not.toBeInTheDocument()
  })

  it('does not follow the reporter to the next ticket they open, or back again', async () => {
    renderPage({ reportOutcome: outcome() })
    await screen.findByRole('heading', { name: /your report was sent/i })

    await userEvent.click(screen.getByRole('button', { name: 'open ticket 2' }))
    await waitFor(() =>
      expect(screen.getByTestId('url')).toHaveTextContent('/app/tickets/ticket-2'),
    )
    await screen.findByText('TKT-0002')
    expect(screen.queryByRole('heading', { name: /your report was sent/i })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'open ticket 1' }))
    await screen.findByText('TKT-0001')
    expect(screen.queryByRole('heading', { name: /your report was sent/i })).not.toBeInTheDocument()
  })

  it('ignores router state of the wrong shape', async () => {
    renderPage({ reportOutcome: { ticketId: 'ticket-1', attached: 'two', failed: [] } })

    await screen.findByRole('heading', { name: 'Projector shows no picture' })
    expect(screen.queryByRole('heading', { name: /your report was sent/i })).not.toBeInTheDocument()
  })
})
