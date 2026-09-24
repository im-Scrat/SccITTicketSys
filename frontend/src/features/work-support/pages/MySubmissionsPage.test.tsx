import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MySubmissionsPage from './MySubmissionsPage'
import type { SupportRequest, TechnicianSubmission } from '../types'

const fetchTechnicianSubmissions = vi.hoisted(() => vi.fn())

vi.mock('../api/workSupportApi', () => ({
  fetchTechnicianSubmissions,
  fetchAttachmentBlob: vi.fn(),
  cancelSupportRequest: vi.fn(),
  acknowledgeSchedule: vi.fn(),
}))

/**
 * The combined submission history (SRS FR-WSR-009).
 *
 * The property this file exists to protect: **both kinds appear, and a reader
 * can tell them apart.** FR-WSR-009 asks for one page covering proof-of-work
 * records *and* support requests, and the failure mode worth catching is a
 * regression that quietly renders only one of them — which a "does the page
 * load" test would sail past.
 *
 * The labels are asserted as *text*, not as styling, because that is what
 * survives grayscale, colour-blindness and a screen reader.
 */
function supportRequest(): SupportRequest {
  return {
    id: 'req-1',
    status: 'submitted',
    status_label: 'Submitted',
    is_undecided: true,
    is_terminal: false,
    explanation: 'The power supply has failed and there is no spare on site.',
    submitted_at: '2026-08-29T09:00:00+00:00',
    technician: { id: 'tech-1', name: 'Sam Reyes' },
    pc_unit: { id: 'pc-1', unit_code: 'PC-LAB4-01', pc_name: 'Lab 4 WS 1', room: 'Laboratory 4' },
    maintenance: null,
    ticket: null,
    items: [{ name: 'ATX power supply, 500W', from_catalog: false, quantity: 1, remarks: null }],
    attachments: [],
    decision: {
      by: null,
      at: null,
      rescheduled_to: null,
      reschedule_reason: null,
      acknowledged_at: null,
      clarification_reason: null,
      proposed_meeting_at: null,
      decline_reason: null,
      cancelled_at: null,
      cancellation_note: null,
      cancelled_by: null,
      closed_at: null,
    },
  }
}

const submissions: TechnicianSubmission[] = [
  {
    kind: 'proof_of_work',
    submitted_at: '2026-08-29T10:00:00+00:00',
    proof_of_work: {
      id: 'job-1',
      title: 'Replace power supply',
      type: 'Corrective',
      status: 'completed',
      status_label: 'Completed',
      pc_unit: { id: 'pc-1', unit_code: 'PC-LAB4-01', pc_name: 'Lab 4 WS 1' },
      ticket: 'TKT-0007',
      resolution: 'Swapped the PSU and retested.',
      started_at: null,
      completed_at: '2026-08-29T10:00:00+00:00',
      evidence: [{ stage: 'after', filename: 'after.jpg', kind: 'image' }],
    },
  },
  {
    kind: 'support_request',
    submitted_at: '2026-08-29T09:00:00+00:00',
    support_request: supportRequest(),
  },
]

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return (
    <MemoryRouter>
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    </MemoryRouter>
  )
}

beforeEach(() => {
  fetchTechnicianSubmissions.mockReset()
})

describe('the combined feed', () => {
  it('renders both kinds and labels each one', async () => {
    fetchTechnicianSubmissions.mockResolvedValue({ data: submissions })

    render(<MySubmissionsPage />, { wrapper })

    /*
     * Wait on card *content*, not on a label: "Proof of work" is also a filter
     * tab, so awaiting the label would resolve immediately against the tab and
     * assert nothing about whether the feed rendered.
     */
    expect(await screen.findByText(/swapped the psu and retested/i)).toBeInTheDocument()
    expect(screen.getByText(/no spare on site/i)).toBeInTheDocument()

    // Both entries are labelled in text, and the labels are the card's own
    // (`p`) rather than the tab strip's buttons.
    const labels = screen
      .getAllByText(/^(Proof of work|Support request)$/)
      .filter((element) => element.tagName === 'P')
      .map((element) => element.textContent)

    expect(labels).toEqual(['Proof of work', 'Support request'])
  })

  it('offers a filter for each kind', async () => {
    fetchTechnicianSubmissions.mockResolvedValue({ data: submissions })

    render(<MySubmissionsPage />, { wrapper })

    expect(await screen.findByRole('tab', { name: 'Everything' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Proof of work' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Support requests' })).toBeInTheDocument()
  })
})

describe('its states', () => {
  it('shows a loading skeleton before the data arrives', () => {
    fetchTechnicianSubmissions.mockReturnValue(new Promise(() => {}))

    render(<MySubmissionsPage />, { wrapper })

    // No card content, and no empty state either — the page is waiting, and it
    // says so with its own shape rather than a spinner or a blank screen.
    expect(screen.queryByText(/swapped the psu and retested/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/have not submitted anything yet/i)).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /my submissions/i })).toBeInTheDocument()
  })

  it('teaches the flow when nothing has been submitted', async () => {
    fetchTechnicianSubmissions.mockResolvedValue({ data: [] })

    render(<MySubmissionsPage />, { wrapper })

    expect(await screen.findByText(/have not submitted anything yet/i)).toBeInTheDocument()
    // The empty state explains how to submit rather than saying "nothing here".
    expect(screen.getByText(/scan the label on a machine/i)).toBeInTheDocument()
  })

  it('reports an error instead of an empty page', async () => {
    fetchTechnicianSubmissions.mockRejectedValue(new Error('network'))

    render(<MySubmissionsPage />, { wrapper })

    expect(await screen.findByText(/could not be loaded/i)).toBeInTheDocument()
  })
})
