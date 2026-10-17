import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DashboardPayload } from '../types'
import TechnicianDashboard from './TechnicianDashboard'

const useDashboard = vi.fn()

vi.mock('../hooks/queries', () => ({ useDashboard: () => useDashboard() }))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ user: { first_name: 'Sam' }, hasPermission: () => true }),
}))

/** What the server sends a technician today — including the panels the page hides. */
const PAYLOAD: DashboardPayload = {
  role: 'technician',
  generated_at: '2026-10-17T10:00:00+08:00',
  widgets: [
    {
      key: 'my-work',
      type: 'kpi',
      title: 'My work',
      href: '/app/tickets/assigned',
      items: [
        { key: 'active_assignments', label: 'Active assignments', value: 5 },
        { key: 'open_tickets', label: 'Open tickets', value: 4 },
        { key: 'at_risk', label: 'Due within 4 h', value: 0, tone: null },
        { key: 'breached', label: 'Overdue', value: 2, tone: 'danger' },
      ],
    },
    {
      key: 'my-assignments',
      type: 'list',
      title: 'My active assignments',
      columns: ['Ticket', 'Priority', 'Due'],
      rows: [
        {
          id: 'tkt-uuid',
          cells: ['TKT-9 · Lab 3 will not boot', 'High', '2026-10-18T10:00:00+08:00'],
          timestamp_cell: 2,
        },
      ],
    },
    {
      key: 'my-assets',
      type: 'kpi',
      title: 'My assets',
      items: [
        { key: 'assigned', label: 'Assigned to me', value: 3 },
        { key: 'maintenance', label: 'Under maintenance', value: 1, tone: 'warning' },
      ],
    },
    {
      key: 'my-assigned-assets',
      type: 'list',
      title: 'Assets assigned to me',
      columns: ['Asset', 'Status', 'Room'],
      rows: [{ id: 'a1', cells: ['AST-1 · Router', 'In use', 'Lab 3'] }],
    },
    {
      key: 'my-assets-under-maintenance',
      type: 'list',
      title: 'My assets under maintenance',
      columns: ['Asset', 'Status', 'Updated'],
      rows: [],
    },
    {
      key: 'my-maintenance',
      type: 'kpi',
      title: 'My maintenance',
      items: [
        { key: 'overdue', label: 'Overdue', value: 0 },
        { key: 'due_soon', label: 'Due in 7 days', value: 2, tone: 'warning' },
        { key: 'in_progress', label: 'In progress', value: 1 },
      ],
    },
    {
      key: 'maintenance-schedule',
      type: 'list',
      title: 'Next maintenance',
      columns: ['Task', 'Target', 'Scheduled'],
      rows: [
        {
          id: 'mnt-uuid',
          cells: ['Replace fan', 'Lab 3 PC 4', '2026-10-20T10:00:00+08:00'],
          timestamp_cell: 2,
        },
      ],
    },
  ],
}

const renderPage = () =>
  render(
    <MemoryRouter>
      <TechnicianDashboard />
    </MemoryRouter>,
  )

beforeEach(() => {
  useDashboard.mockReset()
  useDashboard.mockReturnValue({
    data: PAYLOAD,
    isLoading: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  })
})

describe('TechnicianDashboard — every figure and row opens something', () => {
  it.each([
    ['Active assignments: 5', '/app/tickets/assigned'],
    ['Open tickets: 4', '/app/tickets/assigned?view=open'],
    ['Due within 4 h: 0', '/app/tickets/assigned?view=due-soon'],
    ['Overdue: 2', '/app/tickets/assigned?view=overdue'],
    ['Assigned to me: 3', '/app/my-assets'],
    ['Due in 7 days: 2', '/app/maintenance?due_within=7'],
    ['In progress: 1', '/app/maintenance?status=in_progress'],
  ])('links "%s" to %s', (name, href) => {
    renderPage()

    expect(screen.getByRole('link', { name })).toHaveAttribute('href', href)
  })

  it('links the maintenance Overdue figure too — and at zero', () => {
    renderPage()

    // Two cards are named "Overdue": tickets (2) and maintenance (0).
    expect(screen.getByRole('link', { name: 'Overdue: 0' })).toHaveAttribute(
      'href',
      '/app/maintenance?overdue=1',
    )
  })

  it('opens an active assignment and a maintenance row by their records', () => {
    renderPage()

    expect(screen.getByRole('link', { name: 'TKT-9 · Lab 3 will not boot' })).toHaveAttribute(
      'href',
      '/app/tickets/assigned/tkt-uuid',
    )
    expect(screen.getByRole('link', { name: 'Replace fan' })).toHaveAttribute(
      'href',
      '/app/maintenance/mnt-uuid',
    )
  })

  it('gives each list panel a View all to the full list it previews', () => {
    renderPage()

    const assignments = screen.getByRole('region', { name: 'My active assignments' })
    expect(within(assignments).getByRole('link', { name: /View all/ })).toHaveAttribute(
      'href',
      '/app/tickets/assigned',
    )

    const schedule = screen.getByRole('region', { name: 'Next maintenance' })
    expect(within(schedule).getByRole('link', { name: /View all/ })).toHaveAttribute(
      'href',
      '/app/maintenance/scheduled',
    )
  })
})

describe('TechnicianDashboard — wording and content', () => {
  it('says Overdue, not SLA breached', () => {
    renderPage()

    expect(screen.queryByText(/SLA breached/i)).toBeNull()
    // Two cards carry the word: ticket Overdue and maintenance Overdue.
    expect(screen.getAllByText('Overdue', { selector: 'span' })).toHaveLength(2)
  })

  it('hides the two asset panels that repeat the assets page, and the Under maintenance figure', () => {
    renderPage()

    expect(screen.queryByText('Assets assigned to me')).toBeNull()
    expect(screen.queryByText('My assets under maintenance')).toBeNull()
    expect(screen.queryByText('Under maintenance')).toBeNull()
    expect(screen.getByText('Assigned to me')).toBeInTheDocument()
  })

  it('shows My maintenance as exactly three cards', () => {
    renderPage()

    const region = screen.getByRole('region', { name: 'My maintenance' })
    expect(within(region).getAllByRole('link')).toHaveLength(3)
  })

  it('shows a future due time as "in …"', () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.setSystemTime(new Date('2026-10-17T10:00:00+08:00'))

    try {
      renderPage()
      expect(screen.getByText('in 1d')).toBeInTheDocument()
    } finally {
      vi.useRealTimers()
    }
  })

  it('does not dim the page while it refetches', () => {
    useDashboard.mockReturnValue({
      data: PAYLOAD,
      isLoading: false,
      isError: false,
      isFetching: true,
      refetch: vi.fn(),
    })
    const { container } = renderPage()

    expect(container.querySelector('.opacity-60')).toBeNull()
  })

  it('leaves the QR guidance as it was', () => {
    renderPage()

    expect(screen.getByText('At the machine')).toBeInTheDocument()
  })
})
