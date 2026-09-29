import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TicketAiAnalysis, TicketAiAnalysisEnvelope } from '../types'
import { TicketAiTroubleshooting } from './TicketAiTroubleshooting'

const put = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
  },
}))

function analysis(overrides: Partial<TicketAiAnalysis> = {}): TicketAiAnalysis {
  return {
    ai_generated: true,
    advisory: true,
    confidence: 0.86,
    confidence_threshold: 0.7,
    meets_confidence_threshold: true,
    problem_category: 'Hardware — Display',
    severity: 'medium',
    estimated_resolution_minutes: 30,
    technician_required: false,
    summary: 'The projector is not receiving a signal from the PC.',
    recommendations: [
      {
        step: 2,
        text: 'Press the Source button until the PC input is selected.',
        is_completed: false,
      },
      { step: 1, text: 'Check the HDMI cable is pushed in at both ends.', is_completed: false },
    ],
    analyzed_at: '2026-09-30T08:00:00+08:00',
    ...overrides,
  }
}

function available(
  data: TicketAiAnalysis = analysis(),
  outcome: 'fixed' | 'not_fixed' | null = null,
): TicketAiAnalysisEnvelope {
  return { data, meta: { available: true, reporter_outcome: outcome } }
}

interface Options {
  envelope?: TicketAiAnalysisEnvelope
  statusSlug?: string
  canMarkFixed?: boolean
  canReportNotFixed?: boolean
  isError?: boolean
  error?: unknown
  onRetry?: () => void
}

function renderPanel({
  envelope = available(),
  statusSlug = 'open',
  canMarkFixed = true,
  canReportNotFixed = true,
  isError = false,
  error = null,
  onRetry = vi.fn(),
}: Options = {}) {
  const queryClient = new QueryClient({ defaultOptions: { mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <TicketAiTroubleshooting
        ticketId="ticket-uuid-1"
        statusSlug={statusSlug}
        hasPcUnit
        analysis={envelope}
        analysisUpdatedAt={1}
        isError={isError}
        error={error}
        onRetry={onRetry}
        canMarkFixed={canMarkFixed}
        canReportNotFixed={canReportNotFixed}
        reopenWindowDays={7}
      />
    </QueryClientProvider>,
  )
}

function httpError(status: number) {
  return new AxiosError('failed', String(status), undefined, undefined, {
    status,
    data: {},
    statusText: '',
    headers: {},
    config: {} as never,
  })
}

/** Wait out the requestAnimationFrame the component uses to move focus. */
async function nextFrame() {
  await act(() => new Promise((resolve) => requestAnimationFrame(() => resolve(undefined))))
}

beforeEach(() => {
  put.mockReset()
  post.mockReset()
})

describe('TicketAiTroubleshooting — what it shows', () => {
  it('says plainly that the steps are AI-generated and optional', () => {
    renderPanel()

    const panel = screen.getByRole('region', { name: 'Things to try first' })
    expect(within(panel).getByText('AI-generated')).toBeInTheDocument()
    expect(within(panel).getByText(/trying these is optional/)).toBeInTheDocument()
  })

  it('lists the steps in their order, whatever order they arrive in', () => {
    renderPanel()

    const items = within(screen.getByRole('list')).getAllByRole('listitem')
    expect(items).toHaveLength(2)
    expect(items[0]).toHaveTextContent('Check the HDMI cable')
    expect(items[1]).toHaveTextContent('Press the Source button')
  })

  it('cautions — without hiding the steps — when confidence is below the threshold', () => {
    renderPanel({
      envelope: available(analysis({ meets_confidence_threshold: false, confidence: 0.3 })),
    })

    expect(screen.getByText('These are ideas, not instructions')).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(2)
  })

  it('treats an unknown confidence as not meeting the bar', () => {
    renderPanel({
      envelope: available(analysis({ meets_confidence_threshold: null, confidence: null })),
    })

    expect(screen.getByText('These are ideas, not instructions')).toBeInTheDocument()
  })

  it('does not caution when the threshold was met', () => {
    renderPanel()

    expect(screen.queryByText('These are ideas, not instructions')).not.toBeInTheDocument()
  })

  it('warns against risky steps when a technician is likely needed', () => {
    renderPanel({ envelope: available(analysis({ technician_required: true })) })

    expect(screen.getByText(/may need a technician/)).toBeInTheDocument()
  })

  it('renders nothing at all when there is no analysis yet', () => {
    const { container } = renderPanel({ envelope: { data: null, meta: { available: false } } })

    expect(container).toBeEmptyDOMElement()
  })
})

describe('TicketAiTroubleshooting — FIXED', () => {
  it('confirms inline, sends open -> resolved with the note, and lands focus on the result', async () => {
    put.mockResolvedValue({ data: { data: {} } })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'This fixed it' }))

    const note = screen.getByRole('textbox', { name: 'What fixed it? (optional)' })
    await waitFor(() => expect(note).toHaveFocus())
    expect(screen.getByText(/reopen it for 7 days after it closes/)).toBeInTheDocument()

    fireEvent.change(note, { target: { value: 'Step 1 did it.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Mark as fixed' }))

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith('/tickets/ticket-uuid-1/status', {
        status: 'resolved',
        remarks: 'Step 1 did it.',
      }),
    )

    const recorded = await screen.findByText(/You marked this as fixed/)
    await nextFrame()
    expect(recorded).toHaveFocus()
    expect(screen.queryByRole('button', { name: 'This fixed it' })).not.toBeInTheDocument()
  })

  it('returns focus to the trigger when cancelled, and sends nothing', async () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'This fixed it' }))
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    await nextFrame()

    expect(screen.getByRole('button', { name: 'This fixed it' })).toHaveFocus()
    expect(put).not.toHaveBeenCalled()
  })

  it('shows the server’s refusal in place and keeps the note', async () => {
    put.mockRejectedValue(
      new AxiosError('refused', '422', undefined, undefined, {
        status: 422,
        data: { message: 'This ticket has already moved on.' },
        statusText: '',
        headers: {},
        config: {} as never,
      }),
    )
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'This fixed it' }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'kept' } })
    fireEvent.click(screen.getByRole('button', { name: 'Mark as fixed' }))

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.getByRole('textbox')).toHaveValue('kept')
  })
})

describe('TicketAiTroubleshooting — NOT FIXED', () => {
  it('posts to the not-fixed endpoint and says what happens next', async () => {
    post.mockResolvedValue({ data: { data: {} } })
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Still not working' }))
    fireEvent.change(
      screen.getByRole('textbox', { name: 'What happened when you tried? (optional)' }),
      {
        target: { value: 'Still no picture.' },
      },
    )
    fireEvent.click(screen.getByRole('button', { name: 'Send to the IT team' }))

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith('/tickets/ticket-uuid-1/not-fixed', {
        remarks: 'Still no picture.',
      }),
    )
    expect(await screen.findByText(/back in the queue for a technician/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Still not working' })).not.toBeInTheDocument()
  })

  it('remembers a NOT FIXED from the server, still letting the reporter say it started working', () => {
    renderPanel({ envelope: available(analysis(), 'not_fixed'), canReportNotFixed: false })

    expect(screen.getByText(/didn’t fix it/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Still not working' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Yes, it’s working now' })).toBeInTheDocument()
  })
})

describe('TicketAiTroubleshooting — when the outcome is not the reporter’s to give', () => {
  it('shows the steps read-only once the ticket has moved on', () => {
    renderPanel({ statusSlug: 'in-progress', canMarkFixed: false, canReportNotFixed: false })

    expect(screen.getAllByRole('listitem')).toHaveLength(2)
    expect(screen.queryByText('Did this fix it?')).not.toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('offers nothing to someone the server gave no outcome abilities', () => {
    renderPanel({ canMarkFixed: false, canReportNotFixed: false })

    expect(screen.queryByText('Did this fix it?')).not.toBeInTheDocument()
  })
})

describe('TicketAiTroubleshooting — failures', () => {
  it('renders nothing for a refusal — the panel simply does not apply', () => {
    const { container } = renderPanel({ isError: true, error: httpError(403) })

    expect(container).toBeEmptyDOMElement()
  })

  it('explains a spent rate limit without offering a retry that would fail again', () => {
    renderPanel({ isError: true, error: httpError(429) })

    expect(screen.getByText(/paused for a little while/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
  })

  it('offers a retry for an ordinary failure', () => {
    const onRetry = vi.fn()
    renderPanel({ isError: true, error: httpError(500), onRetry })

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(onRetry).toHaveBeenCalledOnce()
  })
})
