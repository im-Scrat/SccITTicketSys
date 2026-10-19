import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DashboardPayload } from '../types'
import TeacherDashboard from './TeacherDashboard'

const useDashboard = vi.fn()

vi.mock('../hooks/queries', () => ({ useDashboard: () => useDashboard() }))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ user: { first_name: 'Ada' }, hasPermission: () => true }),
}))

// The feed is its own subject; this page's job is the side column.
vi.mock('@/features/tickets/components/CommunityFeedPanel', () => ({
  CommunityFeedHeader: () => <h2>Reported problems</h2>,
  CommunityFeedBody: () => <div>community feed</div>,
}))

/** What the server sends a teacher today: all four panels, in its order. */
const PAYLOAD: DashboardPayload = {
  role: 'teacher',
  generated_at: '2026-10-14T02:00:00+08:00',
  widgets: [
    {
      key: 'my-requests',
      type: 'kpi',
      title: 'My requests',
      href: '/app/tickets/mine',
      items: [
        { key: 'open', label: 'Open', value: 5 },
        {
          key: 'awaiting_confirmation',
          label: 'Awaiting your confirmation',
          value: 1,
          tone: 'warning',
        },
        { key: 'resolved_recently', label: 'Resolved (30 days)', value: 2 },
      ],
    },
    {
      key: 'my-recent-requests',
      type: 'list',
      title: 'Recent requests',
      columns: ['Request', 'Status', 'Reported'],
      rows: [],
      empty: 'You have not reported anything yet.',
    },
    {
      key: 'quick-actions',
      type: 'actions',
      title: 'Quick actions',
      items: [{ key: 'report', label: 'Report a problem', href: '/app/tickets/new' }],
    },
    {
      key: 'announcements',
      type: 'announcements',
      title: 'Announcements',
      rows: [
        {
          id: '1',
          title: 'Network maintenance on Friday',
          content: 'Back by noon.',
          pinned: false,
          at: null,
        },
      ],
    },
  ],
}

function renderDashboard() {
  return render(
    <MemoryRouter>
      <TeacherDashboard />
    </MemoryRouter>,
  )
}

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

describe('TeacherDashboard — side column', () => {
  it('draws "My requests" as one link per figure, once', () => {
    renderDashboard()

    expect(screen.getAllByRole('heading', { name: 'My requests' })).toHaveLength(1)
    expect(screen.getByRole('link', { name: 'Open: 5' })).toHaveAttribute(
      'href',
      '/app/tickets/mine?view=open',
    )
    expect(screen.getByRole('link', { name: 'Awaiting your confirmation: 1' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Resolved (30 days): 2' })).toBeInTheDocument()
  })

  it('does not also draw the figures as the shared KPI cards', () => {
    renderDashboard()

    // Those render as buttons when clickable; a second copy would be one of these.
    expect(screen.queryByRole('button', { name: /Awaiting your confirmation/ })).toBeNull()
  })

  it('keeps dropping the panels the owner removed', () => {
    renderDashboard()

    expect(screen.queryByText('Recent requests')).not.toBeInTheDocument()
    expect(screen.queryByText('Quick actions')).not.toBeInTheDocument()
  })

  it('still shows what else the server sent, after the summary', () => {
    renderDashboard()

    expect(screen.getByText('Network maintenance on Friday')).toBeInTheDocument()

    const summary = screen.getByRole('heading', { name: 'My requests' })
    const announcement = screen.getByText('Network maintenance on Friday')
    expect(
      summary.compareDocumentPosition(announcement) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })

  it('shows a placeholder, not links, while the summary loads', () => {
    useDashboard.mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
      isFetching: true,
      refetch: vi.fn(),
    })

    renderDashboard()

    expect(screen.queryByRole('link', { name: /^Open/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'My requests' })).not.toBeInTheDocument()
  })
})
