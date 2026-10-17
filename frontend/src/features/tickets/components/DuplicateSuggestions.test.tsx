import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TicketCard } from '../types'
import { DuplicateSuggestions } from './DuplicateSuggestions'

/**
 * The similar-reports block sits in the middle of the report form's first
 * section, so it must hold still while the title is typed: no skeleton or
 * collapse between one search and the next.
 */

const get = vi.fn()

vi.mock('@/services/api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn() },
}))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ hasPermission: () => true, hasRole: () => false }),
}))

const MATCH: TicketCard = {
  id: 't-1',
  ticket_number: 'TKT-0042',
  title: 'Projector in Lab 2 will not start',
  excerpt: 'It powers on, then shuts down.',
  status: { slug: 'open', label: 'Open', color: '#2563eb', is_open: true },
  priority: { slug: 'medium', label: 'Medium', level: 2 },
  category: { slug: 'hardware', label: 'Hardware' },
  pc_unit: null,
  asset: null,
  location: { room: 'Lab 2', building: 'Main' },
  reporter: { name: null, label: 'A colleague' },
  upvote_count: 3,
  comment_count: 0,
  has_voted: false,
  is_mine: false,
  created_at: '2026-10-13T08:00:00Z',
}

beforeEach(() => {
  get.mockReset()
})

function renderBlock(search: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const view = (term: string) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DuplicateSuggestions search={term} />
      </MemoryRouter>
    </QueryClientProvider>
  )
  const { rerender, container } = render(view(search))
  return { container, search: (term: string) => rerender(view(term)) }
}

describe('DuplicateSuggestions', () => {
  it('keeps the matches on screen while the next search runs', async () => {
    get.mockResolvedValueOnce({ data: { data: [MATCH] } })
    const block = renderBlock('Projector')
    expect(await screen.findByRole('link', { name: MATCH.title })).toBeInTheDocument()

    get.mockReturnValueOnce(new Promise(() => {}))
    block.search('Projector in Lab')

    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
    expect(screen.getByRole('link', { name: MATCH.title })).toBeInTheDocument()
  })

  it('does not flash a placeholder between searches that found nothing', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } })
    const block = renderBlock('Projector')
    await waitFor(() => expect(get).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(block.container).toBeEmptyDOMElement())

    get.mockReturnValueOnce(new Promise(() => {}))
    block.search('Projector in Lab')

    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
    expect(block.container).toBeEmptyDOMElement()
  })

  it('lists the matches as rows of one list, not cards inside the form’s card', async () => {
    get.mockResolvedValueOnce({ data: { data: [MATCH, { ...MATCH, id: 't-2', title: 'Other' }] } })
    renderBlock('Projector')

    const rows = await screen.findAllByRole('article')
    expect(rows).toHaveLength(2)
    for (const row of rows) expect(row.className).not.toMatch(/\bborder-2\b|\brounded-lg\b/)
  })

  it('keeps nothing from an earlier search once the text is too short to search', async () => {
    get.mockResolvedValueOnce({ data: { data: [MATCH] } })
    const block = renderBlock('Projector')
    expect(await screen.findByRole('link', { name: MATCH.title })).toBeInTheDocument()

    block.search('Pro')

    await waitFor(() => expect(block.container).toBeEmptyDOMElement())
    expect(get).toHaveBeenCalledTimes(1)
  })
})
