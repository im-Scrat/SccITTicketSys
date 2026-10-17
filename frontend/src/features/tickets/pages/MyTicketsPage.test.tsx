import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { API_CATEGORIES } from '../categoryFixtures'
import MyTicketsPage from './MyTicketsPage'

const get = vi.fn()

vi.mock('@/services/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ hasPermission: () => true }),
}))

/** Mirrors the router's search string so the test can read the `?category=` the page wrote. */
function Search() {
  return <output data-testid="search">{useLocation().search}</output>
}

function renderPage(initialEntry = '/app/tickets/mine') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <MyTicketsPage />
        <Search />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const categorySelect = () =>
  screen.getByRole('combobox', { name: /^category/i }) as HTMLSelectElement

const statusSelect = () => screen.getByRole('combobox', { name: /^status/i }) as HTMLSelectElement

/** A view toggle, by its visible text. */
const toggle = (name: string) => screen.getByRole('button', { name })

/** The query string the page has written to the URL so far, parsed. */
const urlParams = () => new URLSearchParams(screen.getByTestId('search').textContent ?? '')

/** The `params` of every `/tickets/mine` request so far, oldest first. */
const mineRequests = () =>
  get.mock.calls
    .filter(([url]) => url === '/tickets/mine')
    .map(([, config]) => (config as { params: Record<string, unknown> }).params)

beforeEach(() => {
  get.mockReset()
  get.mockImplementation((url: string) =>
    Promise.resolve(
      url === '/tickets/options'
        ? {
            data: {
              data: {
                categories: API_CATEGORIES,
                statuses: [
                  {
                    value: 'closed',
                    label: 'Closed',
                    color: '#6b7280',
                    is_open: false,
                    is_terminal: true,
                  },
                ],
                tags: [],
                priorities: [],
                technicians: [],
              },
            },
          }
        : {
            data: {
              data: [],
              meta: { current_page: 1, last_page: 1, total: 0, from: null, to: null },
            },
          },
    ),
  )
})

describe('MyTicketsPage — category filter wiring', () => {
  it('sends the chosen category slug to the API and writes it to the URL', async () => {
    renderPage()
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    await userEvent.selectOptions(categorySelect(), 'power-issue')

    await waitFor(() => expect(mineRequests().at(-1)).toMatchObject({ category: 'power-issue' }))
    expect(screen.getByTestId('search')).toHaveTextContent('?category=power-issue')
  })

  it('"All Categories" removes the category from the request and the URL', async () => {
    renderPage('/app/tickets/mine?category=bios-firmware')
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))
    expect(mineRequests()[0]).toMatchObject({ category: 'bios-firmware' })

    await userEvent.selectOptions(categorySelect(), 'All Categories')

    await waitFor(() => expect(mineRequests().at(-1)?.category).toBeUndefined())
    expect(screen.getByTestId('search').textContent).toBe('')
    expect(categorySelect().value).toBe('')
  })

  it('opens an existing ?category= link on the right option, legacy Other included', async () => {
    renderPage('/app/tickets/mine?category=other')
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    expect(mineRequests()[0]).toMatchObject({ category: 'other' })
    expect(categorySelect().value).toBe('other')
    expect(categorySelect().selectedOptions[0]).toHaveTextContent('Other')
  })

  it('keeps the status filter beside it, unchanged', async () => {
    renderPage()
    await waitFor(() => expect(categorySelect().options).toHaveLength(15))

    expect(screen.getByRole('combobox', { name: /^status/i })).toBeInTheDocument()
  })
})

describe('MyTicketsPage — saved views', () => {
  it.each([
    ['open', 'Open', { status: 'open,assigned,in-progress,on-hold,resolved' }, undefined],
    ['awaiting', 'Awaiting your confirmation', { status: 'resolved' }, undefined],
    ['resolved', 'Resolved (30 days)', { status: 'resolved,closed', resolved_within: 30 }, 30],
  ])(
    '?view=%s asks the API for exactly its preset',
    async (view, label, expected, resolvedWithin) => {
      renderPage(`/app/tickets/mine?view=${view}`)
      await waitFor(() => expect(mineRequests().length).toBeGreaterThan(0))

      expect(mineRequests()[0]).toMatchObject(expected)
      // A number, not the string "30": the server validates it as an integer.
      expect(mineRequests()[0].resolved_within).toBe(resolvedWithin)
      expect(toggle(label)).toHaveAttribute('aria-pressed', 'true')
      expect(toggle('All')).toHaveAttribute('aria-pressed', 'false')
    },
  )

  it('shows everything, with no preset, when no view is chosen', async () => {
    renderPage()
    await waitFor(() => expect(mineRequests().length).toBeGreaterThan(0))

    expect(mineRequests()[0].status).toBeUndefined()
    expect(mineRequests()[0].resolved_within).toBeUndefined()
    expect(toggle('All')).toHaveAttribute('aria-pressed', 'true')
  })

  it('treats a view this build does not know as the unfiltered list', async () => {
    renderPage('/app/tickets/mine?view=bogus')
    await waitFor(() => expect(mineRequests().length).toBeGreaterThan(0))

    expect(mineRequests()[0].status).toBeUndefined()
    expect(toggle('All')).toHaveAttribute('aria-pressed', 'true')
  })

  it('names the active view under the heading', async () => {
    renderPage('/app/tickets/mine?view=resolved')

    expect(
      await screen.findByText('Resolved in the last 30 days, newest first.'),
    ).toBeInTheDocument()
  })

  it('choosing a view writes it to the URL and replaces a status filter', async () => {
    renderPage('/app/tickets/mine?status=closed&category=power-issue')
    await waitFor(() => expect(mineRequests().length).toBeGreaterThan(0))
    expect(mineRequests()[0]).toMatchObject({ status: 'closed' })

    await userEvent.click(toggle('Open'))

    await waitFor(() =>
      expect(mineRequests().at(-1)).toMatchObject({
        status: 'open,assigned,in-progress,on-hold,resolved',
        category: 'power-issue',
      }),
    )
    expect(urlParams().get('view')).toBe('open')
    expect(urlParams().has('status')).toBe(false)
    // Another filter is not the view's to clear.
    expect(urlParams().get('category')).toBe('power-issue')
  })

  it('choosing a status drops the view, so the URL never carries both', async () => {
    renderPage('/app/tickets/mine?view=awaiting')
    await waitFor(() => expect(statusSelect().options.length).toBeGreaterThan(1))

    await userEvent.selectOptions(statusSelect(), 'closed')

    await waitFor(() => expect(mineRequests().at(-1)).toMatchObject({ status: 'closed' }))
    expect(mineRequests().at(-1)?.resolved_within).toBeUndefined()
    expect(urlParams().get('status')).toBe('closed')
    expect(urlParams().has('view')).toBe(false)
    expect(toggle('All')).toHaveAttribute('aria-pressed', 'true')
  })

  it('"All" leaves the view: no preset in the request, nothing in the URL', async () => {
    renderPage('/app/tickets/mine?view=resolved')
    await waitFor(() => expect(mineRequests()[0].resolved_within).toBe(30))

    await userEvent.click(toggle('All'))

    await waitFor(() => expect(mineRequests().at(-1)?.status).toBeUndefined())
    expect(mineRequests().at(-1)?.resolved_within).toBeUndefined()
    expect(screen.getByTestId('search').textContent).toBe('')
  })

  it('does not let a stray ?status= show in the filter bar while a view is active', async () => {
    renderPage('/app/tickets/mine?view=open&status=closed')
    await waitFor(() => expect(mineRequests().length).toBeGreaterThan(0))

    // The request uses the view…
    expect(mineRequests()[0]).toMatchObject({
      status: 'open,assigned,in-progress,on-hold,resolved',
    })
    // …so the Status control must not claim "Closed".
    expect(statusSelect().value).toBe('')
  })

  it('counts an active view as an applied filter, and clearing filters leaves it', async () => {
    renderPage('/app/tickets/mine?view=awaiting')

    expect(await screen.findByText('1 filter applied')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Clear all filters' }))

    await waitFor(() => expect(toggle('All')).toHaveAttribute('aria-pressed', 'true'))
    expect(screen.getByTestId('search').textContent).toBe('')
  })

  it.each([
    ['open', 'Nothing open right now'],
    ['awaiting', 'Nothing waiting for your confirmation'],
    ['resolved', 'Nothing resolved in the last 30 days'],
  ])('says what an empty %s view means, and offers the way back', async (view, title) => {
    renderPage(`/app/tickets/mine?view=${view}`)

    expect(await screen.findByText(title)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Show all my tickets' }))

    expect(await screen.findByText('You have not reported anything yet')).toBeInTheDocument()
    expect(urlParams().has('view')).toBe(false)
  })
})
