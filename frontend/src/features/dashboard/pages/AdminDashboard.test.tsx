import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReportOptions, TicketOverview } from '@/features/analytics/types'
import AdminDashboard from './AdminDashboard'

const useTicketOverview = vi.fn()
const useReportOptions = vi.fn()
const useReportsAccess = vi.fn()

vi.mock('@/features/analytics/hooks/queries', () => ({
  useTicketOverview: (...args: unknown[]) => useTicketOverview(...args),
  useReportOptions: (...args: unknown[]) => useReportOptions(...args),
}))
vi.mock('@/features/analytics/hooks/useReportsAccess', () => ({
  useReportsAccess: () => useReportsAccess(),
}))
vi.mock('../hooks/useAdminDashboardChannel', () => ({
  useAdminDashboardChannel: () => ({ live: true, lastChange: null }),
}))

const drill = (query: Record<string, string>) => ({
  path: '/app/tickets/manage',
  query: { tab: 'directory', ...query },
})

function bucket(key: string, label: string, count: number | null, extra: object = {}) {
  return {
    index: 0,
    key,
    label,
    short_label: label,
    starts_at: '',
    ends_at: '',
    count,
    partial: false,
    in_progress: false,
    before_records: false,
    drilldown: drill({ reported_from: key, reported_to: key }),
    ...extra,
  }
}

const OVERVIEW: TicketOverview = {
  data: {
    kpis: {
      reported: {
        value: 12,
        comparison: 9,
        change: 3,
        change_pct: 33,
        direction: 'up',
        duplicates: 1,
        cancelled: 1,
        drilldown: drill({ reported_from: '2026-10-01', reported_to: '2026-10-18' }),
      },
      daily_average: { value: 0.7, comparison: 0.5, days: 18, comparison_days: 18 },
      open_now: {
        value: 5,
        share_pct: 41.7,
        awaiting_confirmation: 1,
        drilldown: drill({ status: 'open,resolved' }),
      },
      resolved_in_period: { value: 6, drilldown: null },
    },
    status_distribution: {
      total: 12,
      rows: [
        {
          slug: 'open',
          label: 'Open',
          count: 4,
          pct: 33.3,
          is_open: true,
          is_terminal: false,
          drilldown: drill({ status: 'open' }),
        },
        {
          slug: 'resolved',
          label: 'Resolved',
          count: 1,
          pct: 8.3,
          is_open: true,
          is_terminal: false,
          drilldown: drill({ status: 'resolved' }),
        },
        {
          slug: 'closed',
          label: 'Closed',
          count: 7,
          pct: 58.4,
          is_open: false,
          is_terminal: true,
          drilldown: drill({ status: 'closed' }),
        },
        {
          slug: 'on-hold',
          label: 'On Hold',
          count: 0,
          pct: 0,
          is_open: true,
          is_terminal: false,
          drilldown: drill({ status: 'on-hold' }),
        },
      ],
    },
    series: {
      granularity: 'day',
      unit_label: 'day',
      buckets: [
        bucket('2026-10-17', 'Sat 17 Oct 2026', 3),
        bucket('2026-10-18', 'Sun 18 Oct 2026', 1, { in_progress: true }),
      ],
      comparison: {
        buckets: [
          bucket('2026-09-17', 'Thu 17 Sep 2026', 2),
          bucket('2026-09-18', 'Fri 18 Sep 2026', 0),
        ],
        unplotted: { buckets: 0, count: 0 },
      },
      stats: { total: 12, comparison_total: 9, peak: null, empty_buckets: 0 },
    },
    locations: {
      problems_total: 10,
      located: 9,
      by_type: [
        {
          type: 'laboratory',
          label: 'Laboratories',
          count: 7,
          rooms_with_problems: 2,
          rooms_total: 3,
          per_room: 2.3,
          drilldown: drill({ room_type: 'laboratory' }),
        },
        {
          type: 'office',
          label: 'Offices',
          count: 2,
          rooms_with_problems: 1,
          rooms_total: 2,
          per_room: 1,
          drilldown: drill({ room_type: 'office' }),
        },
      ],
      ranking: [
        {
          rank: 1,
          count: 5,
          open: 2,
          share_pct: 55.6,
          room: {
            id: 'room-1',
            name: 'Computer Laboratory 2 — Senior High Building, west wing',
            code: 'LAB-2',
            room_type: 'laboratory',
            room_type_label: 'Laboratory',
            label: 'Main · Ground · Computer Laboratory 2',
            building: { id: 'b-1', name: 'Main Building' },
            archived: true,
            inactive: false,
          },
          drilldown: drill({ room: 'room-1', exclude_duplicates: '1', exclude_cancelled: '1' }),
        },
      ],
      rooms_with_problems: 3,
      others: null,
    },
    summary: {
      sentences: [
        {
          key: 'comparison',
          text: '12 tickets reported in 1–18 Oct 2026, 3 more than in 1–18 Sep 2026 (9), up 33%.',
        },
      ],
      caveats: [
        'Reported includes 1 duplicate and 1 cancelled ticket; the room ranking counts problems and leaves both out.',
      ],
    },
  },
  meta: {
    report_type: 'ticket_overview',
    timezone: 'Asia/Manila',
    timezone_label: 'Asia/Manila (UTC+08:00)',
    generated_at: '2026-10-18T02:31:00Z',
    as_of: '2026-10-18T02:32:00Z',
    as_of_label: 'Data as of 18 Oct 2026, 10:31 (Asia/Manila)',
    today: '2026-10-18',
    period: {
      preset: 'month',
      label: 'October 2026',
      range_label: '1–18 Oct 2026 (to date)',
      from: '2026-10-01',
      to: '2026-10-18',
      starts_at: '',
      ends_at: '',
      to_date: true,
      days: 18,
    },
    comparison: {
      preset: 'month',
      label: '1–18 Sep 2026',
      range_label: '1–18 Sep 2026',
      from: '2026-09-01',
      to: '2026-09-18',
      starts_at: '',
      ends_at: '',
      to_date: false,
      days: 18,
      mode: 'previous',
      description: 'the previous month',
      available: true,
      reason: null,
    },
    granularity: { requested: 'auto', resolved: 'day', max_buckets: 92 },
    filters: {
      room_type: null,
      building: null,
      room: null,
      status: [],
      category: [],
      problem_area: [],
    },
    query: { period: 'month' },
    data_starts_at: '2026-09-02',
    data_quality: { tickets_without_history: 0 },
    definitions: [
      { term: 'Problems', text: 'Reported tickets that are neither a duplicate nor cancelled.' },
    ],
    visible: { locations: true },
  },
}

const OPTIONS: ReportOptions = {
  timezone: 'Asia/Manila',
  timezone_label: 'Asia/Manila (UTC+08:00)',
  today: '2026-10-18',
  data_starts_at: '2026-09-02',
  since_year: { min: 2026, max: 2026 },
  periods: [
    { value: 'day', label: 'Day' },
    { value: 'month', label: 'Month' },
  ],
  granularities: [],
  comparisons: [
    { value: 'none', label: 'No comparison' },
    { value: 'previous', label: 'Previous period' },
  ],
  statuses: [{ value: 'open', label: 'Open', is_open: true, is_terminal: false }],
  categories: [],
  problem_areas: [],
  location_types: [{ value: 'all', label: 'All locations' }],
  buildings: [],
  rooms: [],
}

function loaded(data: TicketOverview | undefined, extra: object = {}) {
  return {
    data,
    isLoading: false,
    isFetching: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
    ...extra,
  }
}

function renderPage(path = '/app') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AdminDashboard />
    </MemoryRouter>,
  )
}

describe('AdminDashboard — ticket overview', () => {
  beforeEach(() => {
    useReportsAccess.mockReturnValue({ canView: true, canExport: true })
    useReportOptions.mockReturnValue(loaded(undefined, { data: OPTIONS }))
    useTicketOverview.mockReturnValue(loaded(OVERVIEW))
  })

  it('shows each headline figure as a link to the list it counts, with its change in words', () => {
    renderPage()

    const reported = screen.getByRole('link', { name: /^Reported: 12\./ })
    expect(reported).toHaveAttribute(
      'href',
      '/app/tickets/manage?tab=directory&reported_from=2026-10-01&reported_to=2026-10-18',
    )
    expect(reported).toHaveAccessibleName(/3 more than 1–18 Sep 2026, up 33%/)
    expect(screen.getByRole('link', { name: /^Still open: 5\./ })).toBeInTheDocument()
    // Resolved in period is an event count: no list can match it exactly.
    expect(screen.queryByRole('link', { name: /Resolved in period/ })).not.toBeInTheDocument()
    expect(screen.getByText('Resolved in period')).toBeInTheDocument()
  })

  it('lists every status present with its share, folds the absent ones, and links each', () => {
    renderPage()

    const table = screen.getByRole('table', { name: 'Tickets by current status' })
    expect(within(table).getByRole('link', { name: 'Closed' })).toHaveAttribute(
      'href',
      '/app/tickets/manage?tab=directory&status=closed',
    )
    expect(within(table).getByText('58.4%')).toBeInTheDocument()
    expect(screen.getByText('Not present: On Hold')).toBeInTheDocument()
  })

  it('ranks rooms with their full name wrapping, and says when a room is archived', () => {
    renderPage()

    const ranking = screen.getByRole('table', { name: 'Rooms ranked by problems' })
    expect(
      within(ranking).getByRole('link', {
        name: /Computer Laboratory 2 — Senior High Building, west wing/,
      }),
    ).toBeInTheDocument()
    expect(within(ranking).getByText('Archived')).toBeInTheDocument()
  })

  it('labels the summary as rule-based, never AI', () => {
    renderPage()

    expect(screen.getByText(/nothing here is generated by AI/)).toBeInTheDocument()
    expect(screen.getByText(/12 tickets reported in 1–18 Oct 2026/)).toBeInTheDocument()
  })

  it('offers the line chart’s figures as a table', () => {
    renderPage()

    expect(screen.getByText('Sat 17 Oct 2026')).toBeInTheDocument()
    expect(screen.getAllByText(/so far/).length).toBeGreaterThan(0)
  })

  it('sends no request and explains why when the viewer may not see reports', () => {
    useReportsAccess.mockReturnValue({ canView: false, canExport: false })

    renderPage()

    expect(screen.getByText(/for administrators with report access/)).toBeInTheDocument()
    expect(useTicketOverview).toHaveBeenCalledWith(expect.anything(), false)
    expect(useReportOptions).toHaveBeenCalledWith(false)
  })

  it('shows the server’s reason for a filter it cannot honour, with a way back', () => {
    useTicketOverview.mockReturnValue(
      loaded(undefined, {
        isError: true,
        error: Object.assign(new Error('422'), {
          isAxiosError: true,
          response: {
            status: 422,
            data: { errors: { granularity: ['Group by day allows at most 92 days.'] } },
          },
        }),
      }),
    )

    renderPage('/app?period=year&granularity=day')

    expect(screen.getByText('Group by day allows at most 92 days.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reset filters' })).toBeInTheDocument()
  })

  it('passes the URL’s filters to the overview, 1:1', () => {
    renderPage('/app?period=week&anchor=2026-10-05&status=open')

    expect(useTicketOverview).toHaveBeenCalledWith(
      { period: 'week', anchor: '2026-10-05', status: 'open' },
      true,
    )
  })

  it('shows a single day as a figure in words rather than a one-point line', () => {
    useTicketOverview.mockReturnValue(
      loaded({
        ...OVERVIEW,
        data: {
          ...OVERVIEW.data,
          series: {
            ...OVERVIEW.data.series,
            buckets: [OVERVIEW.data.series.buckets[0]],
            comparison: null,
          },
        },
      }),
    )

    renderPage('/app?period=day')

    expect(screen.getByText(/Choose a week or longer to see a trend/)).toBeInTheDocument()
  })
})
