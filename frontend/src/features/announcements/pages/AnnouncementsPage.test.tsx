import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AnnouncementsPage from './AnnouncementsPage'
import type { Announcement, Paginated } from '../types'

const fetchAnnouncements = vi.hoisted(() => vi.fn())

vi.mock('../api/announcementsApi', () => ({
  fetchAnnouncements,
  fetchAnnouncement: vi.fn(),
  fetchManagedAnnouncements: vi.fn(),
  createAnnouncement: vi.fn(),
  updateAnnouncement: vi.fn(),
  publishAnnouncement: vi.fn(),
  unpublishAnnouncement: vi.fn(),
  notifyAgain: vi.fn(),
  deleteAnnouncement: vi.fn(),
}))

/**
 * The announcement reader (SRS FR-NOT-011; decision D2).
 *
 * The surface that exists because the dashboard widget is a *summary*: it shows
 * three announcements and silently drops the fourth. These tests hold the
 * properties that make this a reader rather than a second widget — the whole
 * list, the server's order, and states that explain themselves.
 *
 * The audience scope is deliberately **not** asserted here. It is the server's
 * rule, proved in `AnnouncementAuthorizationTest` and in the browser suite; a
 * component test that mocked the API could only assert its own fixture.
 */
function announcement(overrides: Partial<Announcement> = {}): Announcement {
  return {
    id: 'a-1',
    title: 'Network maintenance on Saturday',
    content: 'The staff network is unavailable from 08:00 to 10:00.',
    audience: 'all',
    is_pinned: false,
    starts_at: null,
    ends_at: null,
    created_at: '2026-09-06T08:00:00+00:00',
    ...overrides,
  }
}

function page(rows: Announcement[]): Paginated<Announcement> {
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

  return render(<AnnouncementsPage />, { wrapper })
}

beforeEach(() => {
  vi.clearAllMocks()
  fetchAnnouncements.mockResolvedValue(page([announcement()]))
})

describe('reading announcements', () => {
  it('lists the announcements addressed to this reader', async () => {
    renderPage()

    expect(await screen.findByText('Network maintenance on Saturday')).toBeInTheDocument()
    expect(screen.getByText(/unavailable from 08:00/)).toBeInTheDocument()
  })

  it('renders the body as text, never as markup', async () => {
    // decision D4 — content is plain text, and React escapes it. If a future
    // change introduced dangerouslySetInnerHTML this would render a node.
    fetchAnnouncements.mockResolvedValue(
      page([announcement({ content: '<img src=x onerror="alert(1)">plain' })]),
    )
    renderPage()

    expect(await screen.findByText(/<img src=x onerror="alert\(1\)">plain/)).toBeInTheDocument()
    expect(document.querySelector('img')).toBeNull()
  })

  it('marks a pinned announcement in words, not only with an icon', async () => {
    fetchAnnouncements.mockResolvedValue(page([announcement({ is_pinned: true })]))
    renderPage()

    expect(await screen.findByText('Pinned')).toBeInTheDocument()
  })

  it('names the audience an announcement was sent to', async () => {
    fetchAnnouncements.mockResolvedValue(page([announcement({ audience: 'technicians' })]))
    renderPage()

    expect(await screen.findByText('Technicians')).toBeInTheDocument()
  })

  it('preserves the order the server returned', async () => {
    fetchAnnouncements.mockResolvedValue(
      page([
        announcement({ id: 'a-1', title: 'Pinned first', is_pinned: true }),
        announcement({ id: 'a-2', title: 'Then the rest' }),
      ]),
    )
    renderPage()

    const headings = await screen.findAllByRole('heading', { level: 2 })
    expect(headings[0]).toHaveTextContent('Pinned first')
    expect(headings[1]).toHaveTextContent('Then the rest')
  })
})

describe('states', () => {
  it('shows placeholders while loading', () => {
    fetchAnnouncements.mockReturnValue(new Promise(() => {}))
    renderPage()

    expect(screen.getByRole('status', { name: /loading announcements/i })).toBeInTheDocument()
  })

  it('teaches what belongs here when there is nothing', async () => {
    fetchAnnouncements.mockResolvedValue(page([]))
    renderPage()

    expect(await screen.findByText('No announcements right now')).toBeInTheDocument()
    expect(screen.getByText(/appear here/i)).toBeInTheDocument()
  })

  it('offers a retry when the list fails', async () => {
    fetchAnnouncements.mockRejectedValue(new Error('500'))
    renderPage()

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
  })
})
