import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { OverallSectionResponse } from '../types'
import OverallReportsPage from './OverallReportsPage'

const hooks = vi.hoisted(() => ({
  useOverallSection: vi.fn(),
  useReportOptions: vi.fn(),
  useTicketOverview: vi.fn(),
  useOverallRecords: vi.fn(),
  useEquipmentSearch: vi.fn(),
}))
vi.mock('../hooks/queries', () => hooks)
vi.mock('../hooks/useReportsAccess', () => ({
  useReportsAccess: () => ({ canView: true, canExport: false, canExportRecords: false }),
}))
vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ user: { role: { slug: 'administrator' } }, hasPermission: () => true }),
}))
vi.mock('@/features/dashboard/hooks/useAdminDashboardChannel', () => ({
  useAdminDashboardChannel: () => ({ live: true, lastChange: null }),
}))

const META: OverallSectionResponse['meta'] = {
  report_type: 'overall',
  timezone: 'Asia/Manila',
  generated_at: '2026-10-18T02:00:00Z',
  as_of_label: 'Data as of 18 Oct 2026, 10:00 (Asia/Manila)',
  period: { range_label: '1–18 Oct 2026 (to date)', from: '2026-10-01', to: '2026-10-18' },
  comparison: null,
  sections: [
    { key: 'overview', label: 'Overview' },
    { key: 'tickets', label: 'Tickets' },
    { key: 'repairs', label: 'Repairs & maintenance' },
  ],
  query: {},
  definitions: [{ term: 'Reported', text: 'Tickets filed during the period.' }],
}

const REPAIRS: OverallSectionResponse = {
  meta: META,
  data: {
    key: 'repairs',
    title: 'Repairs & maintenance',
    intro: 'Completed repairs and maintenance.',
    kpis: [
      {
        label: 'Completed repairs',
        value: '1',
        detail: '1 corrective, 0 preventive, 0 upgrades',
        drilldown: null,
        figure: 1,
      },
      { label: 'Parts replaced', value: '0', detail: null, drilldown: null, figure: 0 },
    ],
    charts: [],
    tables: [
      {
        key: 'preventive_candidates',
        title: 'Preventive-care candidates (rule-based)',
        columns: [
          { key: 'code', label: 'PC unit', type: 'code' },
          { key: 'rules', label: 'Rules', type: 'text' },
        ],
        rows: [['PC-001', 'Recurring fault']],
        total_rows: 1,
        truncated: false,
        truncation_note: null,
        note: null,
        is_records: false,
        links: [{ path: '/app/assets/pc-units/u-1', query: {} }],
      },
    ],
    paragraphs: [],
    notes: ['Location is the repaired equipment’s room now.'],
    insufficient: 'Not enough recorded repairs in this period to chart: 1 completed.',
    records: [{ table: 'repairs', title: 'Repair records', total: 1 }],
    not_applicable: ['Ticket status, category and problem area do not apply to repairs.'],
  },
}

function Where() {
  return <span data-testid="where">{useLocation().search}</span>
}

function renderAt(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route
          path="/app/reports"
          element={
            <>
              <OverallReportsPage />
              <Where />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  hooks.useReportOptions.mockReturnValue({ data: undefined, refetch: vi.fn() })
  hooks.useTicketOverview.mockReturnValue({ data: undefined })
  hooks.useEquipmentSearch.mockReturnValue({ data: [], isFetching: false })
  hooks.useOverallRecords.mockReturnValue({
    data: {
      data: {
        table: 'repairs',
        title: 'Repair records',
        columns: [{ key: 'completed', label: 'Completed', type: 'text' }],
        rows: [['2026-10-03 15:00']],
        links: [{ path: '/app/maintenance/m-1', query: {} }],
      },
      meta: {
        total: 1,
        page: 1,
        per_page: 25,
        last_page: 1,
        sort: 'completed',
        direction: 'desc',
        sorts: ['completed', 'type'],
      },
    },
    isFetching: false,
    isError: false,
  })
  hooks.useOverallSection.mockReturnValue({
    data: REPAIRS,
    isFetching: false,
    isLoading: false,
    isError: false,
    isSuccess: true,
    error: null,
    dataUpdatedAt: 1,
    refetch: vi.fn(),
  })
})

describe('OverallReportsPage', () => {
  it('reads the section from the URL and fetches only that section', () => {
    renderAt('/app/reports?section=repairs&period=year')

    expect(hooks.useOverallSection).toHaveBeenCalledWith('repairs', { period: 'year' }, true)
    expect(
      screen.getByRole('heading', { level: 2, name: 'Repairs & maintenance' }),
    ).toBeInTheDocument()
    expect(
      within(screen.getByRole('navigation', { name: 'Report sections' })).getByRole('button', {
        name: 'Repairs & maintenance',
      }),
    ).toHaveAttribute('aria-current', 'page')
  })

  it('shows the figures, the too-little-data panel, what does not apply, and linked rows', () => {
    renderAt('/app/reports?section=repairs')

    expect(screen.getByText('Completed repairs')).toBeInTheDocument()
    expect(screen.getByText('Too little data to chart')).toBeInTheDocument()
    expect(
      screen.getByText('Ticket status, category and problem area do not apply to repairs.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'PC-001' })).toHaveAttribute(
      'href',
      '/app/assets/pc-units/u-1',
    )
  })

  it('offers the record table behind a disclosure, with its total and links', async () => {
    renderAt('/app/reports?section=repairs')

    await userEvent.click(screen.getByText('Repair records · 1'))
    expect(screen.getByRole('link', { name: '2026-10-03 15:00' })).toHaveAttribute(
      'href',
      '/app/maintenance/m-1',
    )
    expect(hooks.useOverallRecords).toHaveBeenCalledWith(
      'repairs',
      {},
      { page: 1, sort: undefined, direction: 'desc' },
    )
  })

  it('moves between sections through the URL', async () => {
    renderAt('/app/reports?section=repairs')

    await userEvent.click(screen.getByRole('button', { name: 'Tickets' }))
    expect(screen.getByTestId('where').textContent).toContain('section=tickets')
  })

  it('explains a section the viewer may not see instead of failing', () => {
    hooks.useOverallSection.mockReturnValue({
      data: undefined,
      isFetching: false,
      isLoading: false,
      isError: true,
      isSuccess: false,
      error: Object.assign(new Error('x'), { isAxiosError: true, response: { status: 403 } }),
      dataUpdatedAt: 0,
      refetch: vi.fn(),
    })
    renderAt('/app/reports?section=repairs')

    expect(screen.getByText('This section is not available to you')).toBeInTheDocument()
  })

  it('marks the repair-only filters as applying to Repairs only', () => {
    renderAt('/app/reports')

    expect(screen.getByText('Applies to Repairs only.')).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Equipment' })).toBeInTheDocument()
  })
})
