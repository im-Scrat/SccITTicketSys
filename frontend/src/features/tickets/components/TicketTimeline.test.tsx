import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import type { TimelineEntry } from '../types'
import { TicketTimeline } from './TicketTimeline'

const get = vi.fn()

vi.mock('@/services/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}))

function renderTimeline(entries: TimelineEntry[]) {
  get.mockResolvedValue({ data: { data: entries } })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <TicketTimeline ticketId="ticket-uuid-1" />
    </QueryClientProvider>,
  )
}

beforeEach(() => get.mockReset())

it('reads the ticket’s own timeline endpoint', async () => {
  renderTimeline([{ kind: 'created', at: '2026-10-01T08:00:00+08:00', actor: 'You', note: null }])

  expect(await screen.findByText('Reported')).toBeInTheDocument()
  expect(get).toHaveBeenCalledWith('/tickets/ticket-uuid-1/timeline')
})

it('describes each kind of entry in plain words, oldest first', async () => {
  renderTimeline([
    { kind: 'created', at: null, actor: 'You', note: null },
    { kind: 'priority', at: null, actor: 'System', note: null, from: 'Low', to: 'High' },
    {
      kind: 'assignment',
      at: null,
      actor: 'IT team',
      note: null,
      reassigned: false,
      technician: null,
    },
    {
      kind: 'outcome',
      at: null,
      actor: 'You',
      note: null,
      outcome: 'not_fixed',
      from: null,
      to: null,
    },
  ])

  const items = await screen.findAllByRole('listitem')
  expect(items.map((item) => item.querySelector('p')?.textContent)).toEqual([
    'Reported',
    'Priority changed from Low to High',
    'Assigned to a technician',
    'Reported that the AI’s suggestions did not fix it',
  ])
})

it('names nobody it was not given — "a technician" for a requester', async () => {
  renderTimeline([
    {
      kind: 'assignment',
      at: null,
      actor: 'IT team',
      note: null,
      reassigned: true,
      technician: null,
    },
  ])

  expect(await screen.findByText('Reassigned to another technician')).toBeInTheDocument()
  expect(screen.getByText('IT team')).toBeInTheDocument()
})

it('says so when nothing has happened yet', async () => {
  renderTimeline([])

  expect(await screen.findByText('Nothing has happened to this ticket yet.')).toBeInTheDocument()
})
