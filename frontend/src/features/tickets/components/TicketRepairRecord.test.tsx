import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError, type AxiosResponse } from 'axios'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TicketDetail, TicketRepairMeta, TicketRepairRecord as RepairRow } from '../types'
import { TicketRepairRecord } from './TicketRepairRecord'

const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    post: (...args: unknown[]) => post(...args),
  },
}))

type TicketProp = Pick<TicketDetail, 'id' | 'ticket_number' | 'title' | 'pc_unit'>

function ticket(overrides: Partial<TicketProp> = {}): TicketProp {
  return {
    id: 'ticket-uuid-1',
    ticket_number: 'TKT-2026-000123',
    title: 'PC shuts down under load',
    pc_unit: { id: 'pc-uuid-1', label: 'Lab 2 — PC 07', identifier: 'PC-L2-07' },
    ...overrides,
  }
}

function row(overrides: Partial<RepairRow> = {}): RepairRow {
  return {
    id: 'mnt-uuid-1',
    title: 'Repair — TKT-2026-000123: PC shuts down under load',
    status: { value: 'in_progress', label: 'In Progress', tone: 'info', is_open: true },
    completed_at: null,
    ...overrides,
  }
}

function MaintenanceStub() {
  const { id } = useParams()
  return <p>maintenance page {id}</p>
}

function renderSection(
  repair: TicketRepairMeta,
  { t = ticket(), readOnly = false }: { t?: TicketProp; readOnly?: boolean } = {},
) {
  const queryClient = new QueryClient({ defaultOptions: { mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/app/tickets/assigned/ticket-uuid-1']}>
        <Routes>
          <Route
            path="/app/tickets/assigned/:id"
            element={<TicketRepairRecord ticket={t} repair={repair} readOnly={readOnly} />}
          />
          <Route path="/app/maintenance/:id" element={<MaintenanceStub />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function created(id: string, concurrent: unknown[] = []) {
  return { data: { data: { id }, meta: { concurrent } } }
}

beforeEach(() => {
  post.mockReset()
})

describe('TicketRepairRecord', () => {
  it('lists the records this repair produced, each linking to its maintenance record', () => {
    renderSection({
      records: [
        row(),
        row({
          id: 'mnt-uuid-0',
          title: 'Earlier visit',
          status: { value: 'completed', label: 'Completed', tone: 'success', is_open: false },
          completed_at: '2026-09-12T10:00:00+08:00',
        }),
      ],
      can_start: false,
    })

    const section = screen.getByRole('region', { name: 'Repair record' })
    const items = within(section).getAllByRole('listitem')
    expect(items).toHaveLength(2)

    expect(within(items[0]).getByRole('link', { name: row().title })).toHaveAttribute(
      'href',
      '/app/maintenance/mnt-uuid-1',
    )
    expect(within(items[0]).getByText('In Progress')).toBeInTheDocument()
    expect(within(items[1]).getByText('Completed')).toBeInTheDocument()
    expect(within(items[1]).getByText(/^Completed \S/)).toBeInTheDocument()

    // An open record is where the work continues; no second one is offered.
    expect(
      screen.getByText(/complete the open record before you mark this ticket done/i),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /repair record/i })).not.toBeInTheDocument()
  })

  it('opens a corrective record linked to this ticket and PC, then goes to it', async () => {
    post.mockResolvedValue(created('mnt-new'))
    renderSection({ records: [], can_start: true })

    fireEvent.click(screen.getByRole('button', { name: 'Start a repair record' }))

    expect(await screen.findByText('maintenance page mnt-new')).toBeInTheDocument()
    expect(post).toHaveBeenCalledWith('/maintenance', {
      title: 'Repair — TKT-2026-000123: PC shuts down under load',
      type: 'corrective',
      pc_unit: 'pc-uuid-1',
      ticket: 'ticket-uuid-1',
    })
  })

  it('offers another record once the earlier ones are finished', () => {
    renderSection({
      records: [
        row({
          status: { value: 'completed', label: 'Completed', tone: 'success', is_open: false },
          completed_at: '2026-09-12T10:00:00+08:00',
        }),
      ],
      can_start: true,
    })

    expect(screen.getByRole('button', { name: 'Start another repair record' })).toBeEnabled()
    expect(screen.queryByText(/complete the open record/i)).not.toBeInTheDocument()
  })

  it('keeps the title within the server limit however long the ticket title is', async () => {
    post.mockResolvedValue(created('mnt-new'))
    renderSection({ records: [], can_start: true }, { t: ticket({ title: 'x'.repeat(400) }) })

    fireEvent.click(screen.getByRole('button', { name: 'Start a repair record' }))

    await screen.findByText('maintenance page mnt-new')
    expect((post.mock.calls[0][1] as { title: string }).title).toHaveLength(255)
  })

  it('stops to show other open work on the machine, and moves focus to the way on', async () => {
    post.mockResolvedValue(
      created('mnt-new', [
        {
          id: 'mnt-other',
          title: 'Term 3 preventive round',
          status: 'scheduled',
          status_label: 'Scheduled',
          type: 'Preventive',
          technician: 'Sam Rivera',
          scheduled_for: null,
        },
      ]),
    )
    renderSection({ records: [], can_start: true })

    fireEvent.click(screen.getByRole('button', { name: 'Start a repair record' }))

    const alert = await screen.findByRole('status')
    expect(within(alert).getByText('This machine already has open maintenance')).toBeInTheDocument()
    expect(within(alert).getByText('Term 3 preventive round')).toBeInTheDocument()

    const onward = within(alert).getByRole('link', { name: 'Continue to my repair record' })
    expect(onward).toHaveAttribute('href', '/app/maintenance/mnt-new')
    await waitFor(() => expect(onward).toHaveFocus())

    // Not navigated, and not offered a duplicate while the warning stands.
    expect(screen.queryByText(/maintenance page/)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /repair record/i })).not.toBeInTheDocument()
  })

  it('says what went wrong and leaves the action available when creation is refused', async () => {
    post.mockRejectedValue(
      new AxiosError('Unprocessable', '422', undefined, undefined, {
        status: 422,
        data: {
          message: 'That ticket is not one you can link this maintenance to.',
          errors: { ticket: ['That ticket is not one you can link this maintenance to.'] },
        },
      } as AxiosResponse),
    )
    renderSection({ records: [], can_start: true })

    fireEvent.click(screen.getByRole('button', { name: 'Start a repair record' }))

    const alert = await screen.findByRole('alert')
    expect(within(alert).getByText('The repair record was not created')).toBeInTheDocument()
    expect(within(alert).getByText(/not one you can link/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Start a repair record' })).toBeEnabled()
  })

  it('explains why there is no record to start when the ticket names no PC', () => {
    renderSection({ records: [], can_start: false }, { t: ticket({ pc_unit: null }) })

    expect(screen.getByText(/this ticket names no pc/i)).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('keeps finished work readable but offers nothing on a read-only ticket', () => {
    renderSection({ records: [row()], can_start: true }, { readOnly: true })

    expect(screen.getByRole('link', { name: row().title })).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.queryByText(/complete the open record/i)).not.toBeInTheDocument()
  })

  it('renders nothing when there is nothing to list and nothing to do', () => {
    const { container } = renderSection({ records: [], can_start: false })
    expect(container).toBeEmptyDOMElement()

    const readOnlyNoPc = renderSection(
      { records: [], can_start: false },
      { t: ticket({ pc_unit: null }), readOnly: true },
    )
    expect(readOnlyNoPc.container).toBeEmptyDOMElement()
  })
})
