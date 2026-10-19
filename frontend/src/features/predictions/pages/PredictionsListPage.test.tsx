import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PredictionListEnvelope, PredictionListItem } from '../types'
import PredictionsListPage from './PredictionsListPage'

const fetchPredictions = vi.hoisted(() => vi.fn())

vi.mock('../api/predictionsApi', () => ({
  fetchPredictions,
  fetchPrediction: vi.fn(),
  confirmPrediction: vi.fn(),
  dismissPrediction: vi.fn(),
}))

/**
 * The Administrator's predictive-maintenance list (WP-M). Mirrors
 * `AnnouncementsPage.test.tsx`'s shape: states first, then the properties
 * that make this a review queue rather than a bare feed — pending sorts
 * first (the server's own order, trusted here), and every card carries risk
 * and status in words, never colour alone.
 */
function item(overrides: Partial<PredictionListItem> = {}): PredictionListItem {
  return {
    id: 'p-1',
    pc_unit: {
      id: 'pc-1',
      label: 'Lab 3 Workstation',
      identifier: 'PC-WPM-01',
      asset_tag: 'AST-9001',
    },
    predicted_issue: 'Power supply failure',
    risk_level: { value: 'high', label: 'High', tone: 'danger' },
    confidence: 0.72,
    status: { value: 'pending', label: 'Needs a decision' },
    generated_at: '2026-09-30T10:00:00+00:00',
    ...overrides,
  }
}

function page(rows: PredictionListItem[]): PredictionListEnvelope {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: rows.length,
      from: rows.length === 0 ? null : 1,
      to: rows.length === 0 ? null : rows.length,
    },
  }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  )

  return render(<PredictionsListPage />, { wrapper })
}

beforeEach(() => {
  vi.clearAllMocks()
  fetchPredictions.mockResolvedValue(page([item()]))
})

describe('predictive-maintenance list', () => {
  it('shows a finding with its PC, risk and status in words', async () => {
    renderPage()

    // Scoped to the finding's own row: "High risk" is also a filter option and
    // "Needs a decision" is also the first tab, so an unscoped query would be
    // matching the controls and proving nothing about the row.
    const row = await screen.findByRole('link', { name: /power supply failure/i })

    expect(within(row).getByText('Lab 3 Workstation')).toBeInTheDocument()
    expect(within(row).getByText('High risk')).toBeInTheDocument()
    expect(within(row).getByText('Needs a decision')).toBeInTheDocument()
  })

  it('defaults to the pending tab', () => {
    renderPage()

    expect(screen.getByRole('tab', { name: /needs a decision/i })).toHaveAttribute(
      'aria-selected',
      'true',
    )
  })

  it('teaches what belongs here when nothing is pending', async () => {
    fetchPredictions.mockResolvedValue(page([]))
    renderPage()

    expect(await screen.findByText('Nothing waiting on you')).toBeInTheDocument()
  })

  it('surfaces a load failure without losing anything', async () => {
    fetchPredictions.mockRejectedValue(new Error('500'))
    renderPage()

    expect(await screen.findByText('Findings could not be loaded')).toBeInTheDocument()
  })
})
