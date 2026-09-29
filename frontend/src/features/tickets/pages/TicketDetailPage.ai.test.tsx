import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TicketAiAnalysisEnvelope, TicketDetail, TicketDetailEnvelope } from '../types'
import TicketDetailPage from './TicketDetailPage'

/**
 * WP-I/WP-J wiring on the reporter's page. The panel's own behaviour is in
 * TicketAiTroubleshooting.test.tsx; this file proves one page-level rule: the
 * reporter's open → resolved move is offered once — in the AI panel when there
 * is an analysis, in the ordinary actions list when there is not.
 */

const get = vi.fn()

vi.mock('@/services/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({
    hasPermission: () => true,
    hasRole: (role: string) => role === 'teacher',
  }),
}))

function ticket(): TicketDetail {
  return {
    id: 'ticket-uuid-1',
    ticket_number: 'TKT-0001',
    title: 'Projector shows no picture',
    description: 'Lab 2 projector says “No signal”.',
    source: 'web',
    status: { slug: 'open', label: 'Open', color: 'blue', is_open: true, is_terminal: false },
    priority: { slug: 'medium', label: 'Medium', color: 'amber', level: 2 },
    category: { slug: 'hardware', label: 'Hardware' },
    reporter: { id: 'user-1', name: 'A. Teacher' },
    technician: null,
    is_assigned: false,
    pc_unit: null,
    location: null,
    upvote_count: 0,
    comment_count: 0,
    attachment_count: 0,
    has_voted: false,
    duplicate_of: null,
    reported_at: '2026-09-30T08:00:00+08:00',
    first_response_at: null,
    resolved_at: null,
    closed_at: null,
    reopened_at: null,
    sla: null,
    ai: null,
    archived: false,
    archived_at: null,
  } as TicketDetail
}

function detail(): TicketDetailEnvelope {
  return {
    data: ticket(),
    meta: {
      transitions: [
        { value: 'resolved', label: 'Resolved', color: 'green', terminal: false },
        { value: 'cancelled', label: 'Cancelled', color: 'gray', terminal: true },
      ],
      can: {
        update: true,
        comment: true,
        comment_internal: false,
        vote: false,
        attach: true,
        confirm_resolution: false,
        reopen: false,
        cancel: true,
        assign: false,
        change_priority: false,
        mark_fixed: true,
        report_not_fixed: true,
      },
      reopen_window_days: 7,
    },
  }
}

function renderPage(analysis: TicketAiAnalysisEnvelope) {
  get.mockImplementation((url: string) =>
    url.endsWith('/ai-analysis')
      ? Promise.resolve({ data: analysis })
      : Promise.resolve({ data: detail() }),
  )

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/tickets/ticket-uuid-1']}>
        <Routes>
          <Route path="/app/tickets/:id" element={<TicketDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  get.mockReset()
})

describe('TicketDetailPage — the reporter’s FIXED move is offered exactly once', () => {
  it('lives in the AI panel when there is an analysis, not in the actions list too', async () => {
    renderPage({
      data: {
        ai_generated: true,
        advisory: true,
        confidence: 0.9,
        confidence_threshold: 0.7,
        meets_confidence_threshold: true,
        problem_category: 'Hardware',
        severity: 'low',
        estimated_resolution_minutes: 10,
        technician_required: false,
        summary: 'Likely the wrong input source.',
        recommendations: [{ step: 1, text: 'Press Source.', is_completed: false }],
        analyzed_at: '2026-09-30T08:01:00+08:00',
      },
      meta: { available: true, reporter_outcome: null },
    })

    const panel = await screen.findByRole('region', { name: 'Things to try first' })
    expect(within(panel).getByRole('button', { name: 'This fixed it' })).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: 'I fixed it myself' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Withdraw this report' })).toBeInTheDocument()
    expect(get).toHaveBeenCalledWith('/tickets/ticket-uuid-1/ai-analysis')
  })

  it('stays in the actions list, in the reporter’s words, when there is no analysis', async () => {
    renderPage({ data: null, meta: { available: false } })

    expect(await screen.findByRole('button', { name: 'I fixed it myself' })).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Things to try first' })).not.toBeInTheDocument()
  })
})
