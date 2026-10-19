import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ProofOfWorkForm } from './ProofOfWorkForm'
import type { ProofResult, WorkTarget } from '../types'

const submitProofOfWork = vi.hoisted(() => vi.fn())

vi.mock('../api/qrApi', () => ({ submitProofOfWork }))

/**
 * The proof-of-work form (SRS FR-MNT-009/010/012; NFR-ACC-*).
 *
 * Two things are asserted here, and neither is styling:
 *
 * **The accessibility tree.** Every query below is by role and accessible name,
 * so the test passes only if a screen-reader user and a keyboard user can reach
 * the same controls a sighted mouse user can. A form that rendered correctly but
 * wired no labels would fail these, which a snapshot never would.
 *
 * **That the client defers.** The selection rule, the evidence rule and the
 * completion gate are the server's. What the client owes is to *carry* the
 * server's answer back to the technician without reinterpreting it — including
 * the case where the server refuses to choose between two jobs and hands back
 * the candidates.
 *
 * These are UX assertions, not security ones. Nothing here proves the API
 * refuses anything; that is `ProofOfWorkTest` and `ProofOfWorkIdempotencyTest`
 * in the backend, which assert against the wire.
 */
function target(overrides: Partial<WorkTarget> = {}): WorkTarget {
  return {
    id: 'job-1',
    title: 'Replace power supply',
    type: 'Corrective',
    status: 'in_progress',
    status_label: 'In progress',
    scheduled_for: null,
    ...overrides,
  }
}

function result(overrides: Partial<ProofResult> = {}): ProofResult {
  return {
    created: false,
    replayed: false,
    evidence_added: 1,
    evidence_skipped: 0,
    maintenance: {
      id: 'job-1',
      title: 'Replace power supply',
      type: 'Corrective',
      status: 'in_progress',
      status_label: 'In progress',
      ticket: null,
      diagnosis: null,
      root_cause: null,
      resolution: 'Swapped the PSU.',
      started_at: null,
      completed_at: null,
      checklist: [],
    },
    evidence: [],
    ...overrides,
  }
}

/** A 422 shaped exactly as Laravel sends one. */
function validationError(data: Record<string, unknown>): AxiosError {
  return new AxiosError('Request failed', '422', undefined, null, {
    status: 422,
    statusText: 'Unprocessable Content',
    data,
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  })
}

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })

  return (
    <MemoryRouter>
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    </MemoryRouter>
  )
}

function renderForm(props: Partial<Parameters<typeof ProofOfWorkForm>[0]> = {}) {
  return render(
    <ProofOfWorkForm
      code="PC-LAB4-01"
      scanId="scan-uuid"
      targets={[target()]}
      mayOpenRecord
      onSubmitted={() => {}}
      {...props}
    />,
    { wrapper },
  )
}

beforeEach(() => {
  submitProofOfWork.mockReset()
})

describe('its accessible structure', () => {
  it('gives every control a name a screen reader can announce', () => {
    renderForm()

    expect(screen.getByRole('button', { name: /add photographs/i })).toBeInTheDocument()
    expect(
      screen.getByRole('combobox', { name: /when these photographs were taken/i }),
    ).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: /what you did/i })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /finished/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /submit proof of work/i })).toBeInTheDocument()
  })

  it('groups the outcome choices so the question is announced with them', () => {
    renderForm()

    expect(screen.getByRole('group', { name: /where does the job stand/i })).toBeInTheDocument()
    expect(screen.getByRole('group', { name: /photographs/i })).toBeInTheDocument()
  })

  it('defaults the outcome to finished, which is what a scan usually follows', () => {
    renderForm()

    expect(screen.getByRole('radio', { name: /finished/i })).toBeChecked()
    expect(screen.getByRole('radio', { name: /still working/i })).not.toBeChecked()
  })
})

describe('choosing a job', () => {
  it('does not ask when the machine carries only one', () => {
    renderForm()

    expect(screen.queryByRole('combobox', { name: /which job is this/i })).not.toBeInTheDocument()
  })

  it('asks when the machine carries two', () => {
    renderForm({ targets: [target(), target({ id: 'job-2', title: 'Clean the fans' })] })

    expect(screen.getByRole('combobox', { name: /which job is this/i })).toBeInTheDocument()
  })

  it('renders the chooser the server sends back when it refuses to guess', async () => {
    // The server found several open jobs mid-submission. The client shows them
    // rather than picking one, and keeps what has already been typed.
    submitProofOfWork.mockRejectedValueOnce(
      validationError({
        message: 'More than one maintenance record is open for this unit.',
        errors: { maintenance_id: ['Choose which maintenance record this work belongs to.'] },
        targets: [target(), target({ id: 'job-2', title: 'Clean the fans' })],
      }),
    )

    const user = userEvent.setup()
    renderForm({ targets: [] })

    await user.type(screen.getByRole('textbox', { name: /what you did/i }), 'Swapped the PSU.')
    await user.click(screen.getByRole('button', { name: /submit proof of work/i }))

    const chooser = await screen.findByRole('combobox', { name: /which job is this/i })

    expect(chooser).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /clean the fans/i })).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: /what you did/i })).toHaveValue('Swapped the PSU.')
  })
})

describe('what it sends', () => {
  it('quotes the scan so a repeat updates one job rather than opening two', async () => {
    submitProofOfWork.mockResolvedValueOnce(result())

    const user = userEvent.setup()
    const onSubmitted = vi.fn()
    renderForm({ onSubmitted })

    await user.type(screen.getByRole('textbox', { name: /what you did/i }), 'Swapped the PSU.')
    await user.click(screen.getByRole('button', { name: /submit proof of work/i }))

    await waitFor(() => expect(submitProofOfWork).toHaveBeenCalledTimes(1))

    expect(submitProofOfWork).toHaveBeenCalledWith(
      'PC-LAB4-01',
      expect.objectContaining({
        scanId: 'scan-uuid',
        resolution: 'Swapped the PSU.',
        outcome: 'completed',
        maintenanceId: 'job-1',
      }),
    )
    expect(onSubmitted).toHaveBeenCalledTimes(1)
  })

  it('never sends a PC identifier — only the scanned code addresses the machine', async () => {
    submitProofOfWork.mockResolvedValueOnce(result())

    const user = userEvent.setup()
    renderForm()

    await user.type(screen.getByRole('textbox', { name: /what you did/i }), 'Swapped the PSU.')
    await user.click(screen.getByRole('button', { name: /submit proof of work/i }))

    await waitFor(() => expect(submitProofOfWork).toHaveBeenCalledTimes(1))

    const [code, submission] = submitProofOfWork.mock.calls[0]

    expect(code).toBe('PC-LAB4-01')
    expect(Object.keys(submission)).not.toContain('pcUnitId')
    expect(Object.keys(submission)).not.toContain('pc_unit')
  })
})

describe('what it does with the servers answers', () => {
  it('shows a blocked completion in the words the lifecycle used', async () => {
    // `status` is the key MaintenanceLifecycle uses for a blocked completion,
    // and its message is the most useful sentence the server can send. The
    // client relays it rather than paraphrasing.
    submitProofOfWork.mockRejectedValueOnce(
      validationError({
        message: 'The given data was invalid.',
        errors: { status: ['One required checklist item is still outstanding.'] },
      }),
    )

    const user = userEvent.setup()
    renderForm()

    await user.type(screen.getByRole('textbox', { name: /what you did/i }), 'Swapped the PSU.')
    await user.click(screen.getByRole('button', { name: /submit proof of work/i }))

    expect(
      await screen.findByText(/one required checklist item is still outstanding/i),
    ).toBeInTheDocument()
  })

  it('reports a rejected photograph against the evidence field', async () => {
    submitProofOfWork.mockRejectedValueOnce(
      validationError({
        message: 'The given data was invalid.',
        errors: {
          'evidence.0': ['The evidence must be a file of type: png, jpg, jpeg, webp, pdf.'],
        },
      }),
    )

    const user = userEvent.setup()
    renderForm()

    await user.type(screen.getByRole('textbox', { name: /what you did/i }), 'Swapped the PSU.')
    await user.click(screen.getByRole('button', { name: /submit proof of work/i }))

    expect(await screen.findByText(/must be a file of type/i)).toBeInTheDocument()
  })
})

describe('when there is nothing to record against', () => {
  it('explains rather than showing a form that would be refused', () => {
    // FR-MNT-009's refusal path, rendered as an explanation. Offering a form
    // the server is certain to reject would waste a technician's photographs.
    renderForm({ targets: [], mayOpenRecord: false })

    expect(screen.getByText(/nothing to record against/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /submit proof of work/i })).not.toBeInTheDocument()
  })

  it('still offers the form when the caller may open a job themselves', () => {
    renderForm({ targets: [], mayOpenRecord: true })

    expect(screen.getByRole('button', { name: /submit proof of work/i })).toBeInTheDocument()
    expect(screen.getByText(/submitting will open one/i)).toBeInTheDocument()
  })
})
