import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, type AxiosResponse } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { API_CATEGORIES } from '../categoryFixtures'
import { ticketsKeys } from '../hooks/queries'
import type { TicketDetail } from '../types'
import { TicketEditDrawer } from './TicketEditDrawer'

const get = vi.fn()
const put = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
  },
}))

function ticketIn(categorySlug: string, detail: string | null = null): TicketDetail {
  return {
    id: 'ticket-uuid-1',
    title: 'Projector will not start',
    description: 'It powers on, then shuts down after a few seconds.',
    category: { slug: categorySlug, label: categorySlug, detail },
  } as unknown as TicketDetail
}

/**
 * The vocabulary is already cached when the drawer opens — the usual case, since
 * the ticket pages load it for their own filters. (See the `todo` at the foot for
 * the case where it is not.)
 */
function renderDrawer(ticket: TicketDetail, onClose = vi.fn(), { cached = true } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  if (cached) {
    queryClient.setQueryData(ticketsKeys.options(), {
      categories: API_CATEGORIES,
      statuses: [],
      tags: [],
      priorities: [],
      technicians: [],
    })
  }

  render(
    <QueryClientProvider client={queryClient}>
      <TicketEditDrawer open onClose={onClose} ticket={ticket} />
    </QueryClientProvider>,
  )

  return { onClose }
}

const categorySelect = () =>
  screen.getByRole('combobox', { name: /what kind of problem is it/i }) as HTMLSelectElement

function refusal(errors: Record<string, string[]>) {
  return new AxiosError('Unprocessable', '422', undefined, undefined, {
    status: 422,
    data: { message: Object.values(errors)[0][0], errors },
  } as AxiosResponse)
}

const detailField = () => screen.getByRole('textbox', { name: /describe the kind of problem/i })

const groupsOf = (select: HTMLSelectElement) =>
  Array.from(select.querySelectorAll('optgroup')).map((group) => ({
    heading: group.getAttribute('label'),
    labels: Array.from(group.querySelectorAll('option')).map((option) => option.textContent),
  }))

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  get.mockResolvedValue({
    data: {
      data: { categories: API_CATEGORIES, statuses: [], tags: [], priorities: [], technicians: [] },
    },
  })
  put.mockResolvedValue({ data: { data: { id: 'ticket-uuid-1' } } })
})

describe('TicketEditDrawer — category dropdown', () => {
  it('lists every category under its heading, in order, legacy Other included', async () => {
    renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    expect(categorySelect().options[0]).toHaveTextContent('Choose one…')
    expect(groupsOf(categorySelect())).toEqual([
      {
        heading: 'Common Problems',
        labels: [
          'Hardware',
          'Software',
          'Network',
          'Performance & System Slowness',
          'Operating System Updates',
          'Power Issue',
          'Audio & Video Output',
          'Browser & Web Issues',
        ],
      },
      {
        heading: 'Advanced / Uncommon Problems',
        labels: [
          'Peripheral',
          'Account & Access',
          'BIOS / Firmware errors',
          'Blue Screen / Kernel Panic',
          'Missing Software',
        ],
      },
      { heading: 'Other Categories', labels: ['Other'] },
    ])
  })

  it('shows the ticket’s current category as the closed value', async () => {
    renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    expect(categorySelect().value).toBe('network')
    expect(categorySelect().selectedOptions[0]).toHaveTextContent('Network')
  })

  it('shows a ticket filed under the legacy Other category as Other', async () => {
    renderDrawer(ticketIn('other'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    expect(categorySelect().value).toBe('other')
    expect(categorySelect().selectedOptions[0]).toHaveTextContent('Other')
  })

  it('saves the API slug of the newly chosen category', async () => {
    const { onClose } = renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.selectOptions(categorySelect(), 'bios-firmware')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(put).toHaveBeenCalledTimes(1))
    expect(put).toHaveBeenCalledWith(
      '/tickets/ticket-uuid-1',
      expect.objectContaining({ category: 'bios-firmware' }),
    )
    await waitFor(() => expect(onClose).toHaveBeenCalled())
  })

  it('leaves an untouched legacy Other ticket on Other when saved', async () => {
    renderDrawer(ticketIn('other'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(
        '/tickets/ticket-uuid-1',
        expect.objectContaining({ category: 'other' }),
      ),
    )
  })

  it('does not ask a legacy Other ticket filed before descriptions existed for one', async () => {
    renderDrawer(ticketIn('other'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    // Shown, because the category takes one — but not demanded.
    expect(detailField()).not.toHaveAttribute('aria-required')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(
        '/tickets/ticket-uuid-1',
        expect.objectContaining({ category: 'other', category_detail: null }),
      ),
    )
  })

  // Pre-existing, independent of the grouping (the original flat list behaves the
  // same): when the options are NOT cached and arrive after the drawer opens, the
  // form is reset before the matching <option> exists, so the closed select reads
  // "Choose one…" although the ticket has a category. Saving untouched is still
  // correct — the form value is kept — so this is display only. Left as found.
  it.todo('shows the current category when the options arrive after the drawer has opened')
})

describe('TicketEditDrawer — the "Other" description', () => {
  it('shows a flagged ticket’s description, ready to reword', async () => {
    renderDrawer(ticketIn('other', 'Smartboard pen'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    expect(detailField()).toHaveValue('Smartboard pen')
    expect(detailField()).toHaveAttribute('aria-required', 'true')
  })

  it('has no description field for a category that asks for none', async () => {
    renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    expect(
      screen.queryByRole('textbox', { name: /describe the kind of problem/i }),
    ).not.toBeInTheDocument()
  })

  it('asks for a description when the category changes into an Other one, and saves nothing until given', async () => {
    renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.selectOptions(categorySelect(), 'other')
    expect(detailField()).toHaveAttribute('aria-required', 'true')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    expect(
      await screen.findByText(/say in a few words what kind of problem it is/i),
    ).toBeInTheDocument()
    expect(put).not.toHaveBeenCalled()

    await userEvent.type(detailField(), '  Smartboard pen  ')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(
        '/tickets/ticket-uuid-1',
        expect.objectContaining({ category: 'other', category_detail: 'Smartboard pen' }),
      ),
    )
  })

  it('shows a refusal for a field it is not showing, and moves focus to it', async () => {
    put.mockRejectedValue(
      refusal({ category_detail: ['The description can be changed, but not removed.'] }),
    )
    renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('The description can be changed, but not removed.')
    await waitFor(() => expect(alert.parentElement).toHaveFocus())
  })

  it('moves focus to a field the server refused', async () => {
    put.mockRejectedValue(refusal({ title: ['Give the problem a clearer title.'] }))
    renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    expect(await screen.findByText('Give the problem a clearer title.')).toBeInTheDocument()
    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: /what is the problem/i })).toHaveFocus(),
    )
  })

  it('saves once however often it is asked while saving, without disabling Save', async () => {
    let finish: () => void = () => {}
    put.mockReturnValue(
      new Promise((resolve) => {
        finish = () => resolve({ data: { data: { id: 'ticket-uuid-1' } } })
      }),
    )
    const { onClose } = renderDrawer(ticketIn('network'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))
    const save = screen.getByRole('button', { name: /save changes/i })
    const form = categorySelect().closest('form') as HTMLFormElement

    await userEvent.click(save)
    await waitFor(() => expect(put).toHaveBeenCalledTimes(1))
    // Disabled, it would drop keyboard focus; its spinner says it is busy instead.
    expect(save).toBeEnabled()
    expect(save).toHaveAttribute('aria-busy', 'true')

    fireEvent.submit(form)
    fireEvent.submit(form)
    await userEvent.click(save)
    expect(put).toHaveBeenCalledTimes(1)

    finish()
    await waitFor(() => expect(onClose).toHaveBeenCalled())
    expect(put).toHaveBeenCalledTimes(1)
  })

  it('will not let an edit erase a description the ticket already has', async () => {
    renderDrawer(ticketIn('other', 'Card reader'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.clear(detailField())
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    expect(
      await screen.findByText(/say in a few words what kind of problem it is/i),
    ).toBeInTheDocument()
    expect(put).not.toHaveBeenCalled()
  })

  it('leaves the description out of a save made before the categories arrive', async () => {
    // Which categories take a description is part of the options; without
    // them, sending null would ask the server to erase this one.
    get.mockReturnValue(new Promise(() => {}))
    renderDrawer(ticketIn('other', 'Smartboard pen'), vi.fn(), { cached: false })

    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(put).toHaveBeenCalledTimes(1))
    expect(put.mock.calls[0][1]).toMatchObject({ category: 'other' })
    expect(put.mock.calls[0][1]).not.toHaveProperty('category_detail')
  })

  it('drops the description when the category changes to one that asks for none', async () => {
    renderDrawer(ticketIn('other', 'Smartboard pen'))
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.selectOptions(categorySelect(), 'hardware')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith(
        '/tickets/ticket-uuid-1',
        expect.objectContaining({ category: 'hardware', category_detail: null }),
      ),
    )
  })
})
