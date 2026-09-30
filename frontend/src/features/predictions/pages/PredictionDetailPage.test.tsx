import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PredictionDetailEnvelope } from '../types'
import PredictionDetailPage from './PredictionDetailPage'

const fetchPrediction = vi.hoisted(() => vi.fn())
const confirmPrediction = vi.hoisted(() => vi.fn())
const dismissPrediction = vi.hoisted(() => vi.fn())

vi.mock('../api/predictionsApi', () => ({
  fetchPredictions: vi.fn(),
  fetchPrediction,
  confirmPrediction,
  dismissPrediction,
}))

/**
 * The full evidence view for one predictive-maintenance finding (WP-M).
 *
 * Confirm/dismiss are terminal (`PcPredictionDecision` refuses a second
 * decision), so both go through the same confirmation dialog every other
 * one-way decision in this codebase uses — these tests hold that the buttons
 * only appear when `can.decide` is true, and that the dialog, not the button
 * itself, is what triggers the mutation.
 */
function detail(overrides: Partial<PredictionDetailEnvelope['data']> = {}): PredictionDetailEnvelope {
  return {
    data: {
      id: 'p-1',
      pc_unit: { id: 'pc-1', label: 'Lab 3 Workstation', identifier: 'PC-WPM-01', asset_tag: 'AST-9001' },
      location: { room: 'Lab 3', floor: 'Second Floor', building: 'Science Hall' },
      predicted_issue: 'Power supply failure',
      risk_level: { value: 'high', label: 'High', tone: 'danger' },
      probability: null,
      confidence: 0.72,
      predicted_within_days: 50,
      explanation: 'Three power supply replacements at regular ~60-day intervals.',
      recommendation: 'Inspect ventilation before the next interval elapses.',
      evidence: {
        observed: {
          completed_repairs: 3,
          corrective_repairs: 3,
          preventive_visits: 0,
          last_completed_at: '2026-08-20T00:00:00+00:00',
          components_replaced: [{ component_type: 'power_supply', label: 'Power Supply', count: 3 }],
        },
        patterns: [
          {
            kind: 'component',
            name: 'Recurring Power Supply replacement',
            detected_problem: 'Power Supply replaced in 3 separate repairs',
            occurrence_count: 3,
            intervals_days: [60, 60],
            average_days_between: 60,
            first_at: '2026-05-01T00:00:00+00:00',
            last_at: '2026-08-20T00:00:00+00:00',
            records: [],
          },
        ],
        time_window: { days: 50, basis: "The strongest pattern's average interval." },
      },
      ai_model: { provider: 'gemini', model: 'gemini-1.5-flash' },
      failure_pattern: { id: 1, name: 'Recurring Power Supply replacement', occurrence_count: 3 },
      status: { value: 'pending', label: 'Needs a decision' },
      can: { decide: true },
      generated_at: '2026-09-30T10:00:00+00:00',
      created_at: '2026-09-30T10:00:00+00:00',
      ...overrides,
    },
  }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/predictions/p-1']}>
        <Routes>
          <Route path="/app/predictions/:id" element={<PredictionDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  fetchPrediction.mockResolvedValue(detail())
})

describe('predictive-maintenance detail', () => {
  it('serves the evidence a decision is made from, structured, not as prose', async () => {
    renderPage()

    expect(await screen.findByText('Power supply failure')).toBeInTheDocument()
    expect(screen.getByText(/Lab 3.*Second Floor.*Science Hall/)).toBeInTheDocument()
    expect(screen.getByText('Recurring Power Supply replacement')).toBeInTheDocument()
    expect(screen.getByText('Power Supply')).toBeInTheDocument()
    expect(screen.getByText('3 times')).toBeInTheDocument()
  })

  it('states the probability is unavailable rather than omitting it', async () => {
    renderPage()

    await screen.findByText('Power supply failure')
    expect(screen.getByText('No calibrated failure model exists yet.')).toBeInTheDocument()
  })

  it('offers confirm/dismiss only while a decision is pending', async () => {
    fetchPrediction.mockResolvedValue(
      detail({ status: { value: 'confirmed', label: 'Confirmed' } }),
    )
    renderPage()

    await screen.findByText('Power supply failure')
    expect(screen.queryByRole('button', { name: /confirm finding/i })).not.toBeInTheDocument()
    expect(screen.getByText(/decisions on a predictive-maintenance finding are final/i)).toBeInTheDocument()
  })

  it('confirms only after the dialog is accepted, not on the first click', async () => {
    const user = userEvent.setup()
    confirmPrediction.mockResolvedValue(detail({ status: { value: 'confirmed', label: 'Confirmed' } }))
    renderPage()

    await user.click(await screen.findByRole('button', { name: /confirm finding/i }))
    expect(confirmPrediction).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'Confirm finding' }))

    await waitFor(() => expect(confirmPrediction).toHaveBeenCalledWith('p-1'))
  })

  it('surfaces a failed decision without pretending it was recorded', async () => {
    const user = userEvent.setup()
    dismissPrediction.mockRejectedValue(new Error('This finding was already decided.'))
    renderPage()

    await user.click(await screen.findByRole('button', { name: /^dismiss$/i }))
    await user.click(screen.getByRole('button', { name: 'Dismiss finding' }))

    expect(await screen.findByText('That decision was not recorded')).toBeInTheDocument()
  })
})
