import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import indexCss from '@/index.css?raw'
import type { ReportDocument } from '../types'
import ReportPrintPage from './ReportPrintPage'

const fetchPrintDocument = vi.hoisted(() => vi.fn())
vi.mock('../api/reportsApi', () => ({ fetchPrintDocument }))

const DOCUMENT: ReportDocument = {
  type: 'ticket_overview',
  title: 'Ticket overview',
  organisation: 'SccIT',
  period_label: '1–18 Oct 2026 (to date)',
  comparison_label: '1–18 Sep 2026 (the previous month)',
  timezone_label: 'Asia/Manila (UTC+08:00)',
  as_of_label: 'Data as of 18 Oct 2026, 10:00 (Asia/Manila)',
  generated_label: 'Generated 18 Oct 2026, 10:01 (Asia/Manila)',
  filters: [{ label: 'Location', value: 'All laboratories' }],
  summary: ['12 tickets were reported, 3 more than 1–18 Sep 2026.'],
  summary_label: 'Calculated from recorded tickets (fixed rules, not AI)',
  caveats: [],
  sections: [
    {
      key: 'statuses',
      title: 'Current status',
      intro: null,
      kpis: [],
      charts: [
        {
          kind: 'donut',
          title: 'Current status of tickets reported',
          description: 'The share of reported tickets in each status now.',
          table_key: 'statuses',
          image: 'data:image/svg+xml;base64,PHN2Zy8+',
        },
      ],
      tables: [
        {
          key: 'statuses',
          title: 'Current status',
          columns: [
            { key: 'status', label: 'Status', type: 'text' },
            { key: 'count', label: 'Tickets', type: 'number' },
          ],
          rows: [['Open', 1234]],
          total_rows: 1,
          truncated: false,
          truncation_note: null,
          note: null,
          is_records: false,
        },
      ],
      paragraphs: [],
      notes: [],
    },
  ],
  definitions: [{ term: 'Reported', definition: 'Tickets filed during the period.' }],
  limitations: ['Times are school time.'],
  profile: 'full',
  records_included: false,
}

function Search() {
  return <span data-testid="search">{useLocation().search}</span>
}

function renderAt(url: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[url]}>
        <Routes>
          <Route
            path="/app/dashboard/print"
            element={
              <>
                <ReportPrintPage
                  reportType="ticket_overview"
                  backPath="/app"
                  backLabel="Back to the dashboard"
                />
                <Search />
              </>
            }
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ReportPrintPage', () => {
  const print = vi.fn()

  beforeEach(() => {
    fetchPrintDocument.mockReset().mockResolvedValue(DOCUMENT)
    print.mockReset()
    vi.stubGlobal('print', print)
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
      cb(0)
      return 0
    })
  })
  afterEach(() => vi.unstubAllGlobals())

  it('renders the document in a light subtree with every part the PDF has', async () => {
    const { container } = renderAt('/app/dashboard/print?period=month&room_type=laboratory')

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Ticket overview' }),
    ).toBeInTheDocument()
    expect(container.querySelector('[data-theme="light"]')).not.toBeNull()
    expect(screen.getByText('All laboratories')).toBeInTheDocument()
    expect(screen.getByText('1,234')).toBeInTheDocument()
    expect(
      screen.getByRole('img', { name: 'The share of reported tickets in each status now.' }),
    ).toHaveAttribute('src', 'data:image/svg+xml;base64,PHN2Zy8+')
    expect(screen.getByText('Tickets filed during the period.')).toBeInTheDocument()
    expect(fetchPrintDocument).toHaveBeenCalledWith({
      report_type: 'ticket_overview',
      params: { period: 'month', room_type: 'laboratory' },
      include_records: false,
      include_narrative: false,
    })
  })

  it('prints once and drops autoprint from the URL, so a reload does not print again', async () => {
    renderAt('/app/dashboard/print?period=month&autoprint=1')

    await waitFor(() => expect(print).toHaveBeenCalledTimes(1))
    expect(screen.getByTestId('search').textContent).toBe('?period=month')
  })

  it('does not print on its own without autoprint', async () => {
    renderAt('/app/dashboard/print?period=month')

    await screen.findByRole('heading', { level: 1, name: 'Ticket overview' })
    expect(print).not.toHaveBeenCalled()
  })
})

describe('print stylesheet', () => {
  it('declares the light tokens for light subtrees and keeps the dark ones off paper', () => {
    expect(indexCss).toMatch(/:root,\s*\[data-theme='light'\]\s*\{\s*color-scheme: light;/)
    expect(indexCss).toMatch(/@media not print \{\s*\[data-theme='dark'\] \{\s*color-scheme: dark;/)
    expect(indexCss).not.toMatch(/@page\s*\{[^}]*size:/)
  })
})
