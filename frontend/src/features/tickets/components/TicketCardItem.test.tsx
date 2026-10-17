import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TicketCard } from '../types'
import { TicketCardItem } from './TicketCardItem'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
  },
}))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ hasPermission: () => true, hasRole: () => false }),
}))

const TICKET: TicketCard = {
  id: 't-1',
  ticket_number: 'TKT-0042',
  title: 'Printer in Lab 2 jams on every job',
  excerpt: 'Paper feeds halfway and stops.',
  status: { slug: 'open', label: 'Open', color: '#2563eb', is_open: true },
  priority: { slug: 'medium', label: 'Medium', level: 2 },
  category: { slug: 'printer', label: 'Printer' },
  pc_unit: null,
  asset: null,
  location: { room: 'Lab 2', building: 'Main' },
  reporter: { name: null, label: 'A colleague' },
  upvote_count: 3,
  comment_count: 2,
  has_voted: false,
  is_mine: false,
  created_at: '2026-10-13T08:00:00Z',
}

/** Mirrors the router's path so a test can tell whether the card navigated. */
function Path() {
  return <output data-testid="path">{useLocation().pathname}</output>
}

function renderCard(props: Partial<Parameters<typeof TicketCardItem>[0]> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app']}>
        <Routes>
          <Route path="/app" element={<TicketCardItem ticket={TICKET} {...props} />} />
          <Route path="/app/tickets/:id" element={<p>Ticket page</p>} />
        </Routes>
        <Path />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const path = () => screen.getByTestId('path').textContent

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  get.mockResolvedValue({
    data: {
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
    },
  })
  post.mockResolvedValue({ data: { data: { voted: true, upvote_count: 4 } } })
})

describe('TicketCardItem', () => {
  it('makes the whole card one link to the ticket', async () => {
    renderCard()

    const link = screen.getByRole('link', { name: TICKET.title })
    expect(link).toHaveAttribute('href', '/app/tickets/t-1')
    // Stretched over the card: the pseudo-element is what makes every part of
    // the card a click on this link (geometry is verified in the browser).
    expect(link).toHaveAttribute('data-card-link')
    expect(link.className).toContain('after:inset-0')
    // The owner's call: hovering the card darkens its border, it does not
    // underline the title.
    expect(link.className).not.toContain('hover:underline')
    // There is still only one link: a screen reader hears the title once.
    expect(screen.getAllByRole('link')).toHaveLength(1)

    await userEvent.click(link)
    expect(path()).toBe('/app/tickets/t-1')
  })

  it('votes without opening the ticket', async () => {
    renderCard()

    await userEvent.click(screen.getByRole('button', { name: /upvote/i }))

    await waitFor(() => expect(post).toHaveBeenCalledWith('/tickets/t-1/vote'))
    expect(path()).toBe('/app')
  })

  it('opens the conversation in a dialog without leaving the list', async () => {
    renderCard()

    const button = screen.getByRole('button', { name: '2 comments' })
    expect(button).toHaveAttribute('aria-haspopup', 'dialog')
    await userEvent.click(button)

    const dialog = await screen.findByRole('dialog', { name: 'Comments' })
    // Described by reference and title, so it is clear which ticket is open.
    expect(within(dialog).getByText(TICKET.title, { exact: false })).toHaveTextContent(
      `${TICKET.ticket_number} · ${TICKET.title}`,
    )
    expect(within(dialog).getByRole('link', { name: /open full ticket/i })).toHaveAttribute(
      'href',
      '/app/tickets/t-1',
    )
    await waitFor(() =>
      expect(get).toHaveBeenCalledWith('/tickets/t-1/comments', { params: { page: 1 } }),
    )
    // Anyone holding `tickets.comment` can write from the dialog.
    expect(within(dialog).getByLabelText('Add a comment')).toBeInTheDocument()
    expect(path()).toBe('/app')
  })

  it('names the other equipment a ticket was filed against, as a label only', () => {
    renderCard({
      ticket: { ...TICKET, asset: { label: 'Epson EB-X41 projector', identifier: 'AST-0007' } },
    })

    const item = screen.getByText('Affected item').parentElement as HTMLElement
    expect(item).toHaveTextContent('Epson EB-X41 projector (AST-0007)')
    // The card carries no id for it, so there is nothing to link to.
    expect(screen.getAllByRole('link')).toHaveLength(1)
  })

  it('invites the first comment when there are none', () => {
    renderCard({ ticket: { ...TICKET, comment_count: 0 } })

    expect(screen.getByRole('button', { name: 'Comment' })).toBeInTheDocument()
  })

  it('keeps a compact card to a title link and a plain count', () => {
    // Compact cards sit inside the report form, where a stray click on the
    // card must not abandon a half-written report.
    renderCard({ compact: true })

    const link = screen.getByRole('link', { name: TICKET.title })
    expect(link).not.toHaveAttribute('data-card-link')
    // Here the title is the only link, so it keeps the underline as its hover cue.
    expect(link.className).toContain('hover:underline')
    expect(screen.queryByRole('button', { name: /comment/i })).not.toBeInTheDocument()
    expect(screen.getByText('2 comments')).toBeInTheDocument()
  })
})
