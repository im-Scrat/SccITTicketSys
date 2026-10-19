import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SupportRequestCard } from './SupportRequestCard'
import type { SupportRequest, SupportRequestDecision } from '../types'

/**
 * The one card both surfaces render (SRS FR-WSR-004/005/008/009).
 *
 * The property worth testing is the one that would be easiest to lose: **a
 * decision never hides the one before it.** FR-WSR-004 forbids overwriting a
 * prior workflow state in place, and a card that switched on `status` would
 * satisfy the database while making the history invisible to the person it was
 * kept for.
 *
 * The second property is that a technician can always read *why* a request was
 * declined. That sentence is the entire point of FR-WSR-008, and it is the one
 * most likely to be dropped from a card that grew a "compact" variant.
 */
function decision(overrides: Partial<SupportRequestDecision> = {}): SupportRequestDecision {
  return {
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
    ...overrides,
  }
}

function request(overrides: Partial<SupportRequest> = {}): SupportRequest {
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
    items: [{ name: 'ATX power supply, 500W', from_catalog: false, quantity: 2, remarks: null }],
    attachments: [],
    decision: decision(),
    ...overrides,
  }
}

describe('what every card shows', () => {
  it('names the machine, the items and the explanation', () => {
    render(<SupportRequestCard request={request()} />)

    expect(screen.getByText('PC-LAB4-01')).toBeInTheDocument()
    expect(screen.getByText('ATX power supply, 500W')).toBeInTheDocument()
    expect(screen.getByText(/no spare on site/i)).toBeInTheDocument()
    expect(screen.getByText('×2')).toBeInTheDocument()
  })

  it('carries the status as text, never as colour alone', () => {
    // NFR-ACC: the label is what survives grayscale and colour-blindness.
    render(<SupportRequestCard request={request({ status_label: 'Face-to-face requested' })} />)

    expect(screen.getByText('Face-to-face requested')).toBeInTheDocument()
  })

  it('names the technician only where the surface asks for it', () => {
    const { rerender } = render(<SupportRequestCard request={request()} />)

    expect(screen.queryByText(/asked by sam reyes/i)).not.toBeInTheDocument()

    rerender(<SupportRequestCard request={request()} showTechnician />)

    expect(screen.getByText(/asked by sam reyes/i)).toBeInTheDocument()
  })
})

describe('what a decision shows', () => {
  it('always shows the decline reason', () => {
    render(
      <SupportRequestCard
        request={request({
          status: 'declined',
          status_label: 'Declined',
          is_undecided: false,
          decision: decision({
            decline_reason: 'No budget this term; raise it in the next procurement round.',
            by: { id: 'admin-1', name: 'Ada Chen' },
            at: '2026-08-29T10:00:00+00:00',
          }),
        })}
      />,
    )

    expect(screen.getByText(/no budget this term/i)).toBeInTheDocument()
    expect(screen.getByText(/decided by ada chen/i)).toBeInTheDocument()
  })

  it('shows a reschedule and whether it has been acknowledged', () => {
    render(
      <SupportRequestCard
        request={request({
          status: 'approved',
          status_label: 'Approved',
          is_undecided: false,
          decision: decision({
            rescheduled_to: '2026-09-05T09:00:00+00:00',
            reschedule_reason: 'The part arrives on Monday.',
            acknowledged_at: '2026-08-30T08:00:00+00:00',
          }),
        })}
      />,
    )

    expect(screen.getByText(/approved — work rescheduled to/i)).toBeInTheDocument()
    expect(screen.getByText(/the part arrives on monday/i)).toBeInTheDocument()
    expect(screen.getByText(/you acknowledged this/i)).toBeInTheDocument()
  })

  it('keeps an earlier face-to-face request visible after a later decline', () => {
    /*
     * The FR-WSR-004 property, made visible. A card that switched on `status`
     * would show only the decline, and the technician would lose the fact that
     * they were asked to come and discuss it first.
     */
    render(
      <SupportRequestCard
        request={request({
          status: 'declined',
          status_label: 'Declined',
          is_undecided: false,
          decision: decision({
            clarification_reason: 'Come and show me why the whole unit needs replacing.',
            decline_reason: 'After discussing it, a repair is the better option this term.',
          }),
        })}
      />,
    )

    expect(screen.getByText(/come and show me why/i)).toBeInTheDocument()
    expect(screen.getByText(/a repair is the better option/i)).toBeInTheDocument()
  })

  it('shows a withdrawal and who made it', () => {
    render(
      <SupportRequestCard
        request={request({
          status: 'cancelled',
          status_label: 'Cancelled',
          is_undecided: false,
          is_terminal: true,
          decision: decision({
            cancelled_at: '2026-08-29T11:00:00+00:00',
            cancelled_by: 'Sam Reyes',
            cancellation_note: 'Found a spare in the store cupboard.',
          }),
        })}
      />,
    )

    expect(screen.getByText(/withdrawn by sam reyes/i)).toBeInTheDocument()
    expect(screen.getByText(/found a spare/i)).toBeInTheDocument()
  })

  it('shows no decision block at all on a fresh request', () => {
    render(<SupportRequestCard request={request()} />)

    expect(screen.queryByText(/decided by/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/declined/i)).not.toBeInTheDocument()
  })
})
