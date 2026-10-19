import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MaintenanceQueuePage from './MaintenanceQueuePage'

const get = vi.fn()

vi.mock('@/services/api', () => ({
  api: { get: (...args: unknown[]) => get(...args) },
}))

vi.mock('@/features/auth/hooks/useAuth', () => ({
  useAuth: () => ({ hasPermission: () => false }),
}))

function renderPage(initialEntry: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <MaintenanceQueuePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const queueRequests = () =>
  get.mock.calls
    .filter(([url]) => url === '/maintenance')
    .map(([, config]) => (config as { params: Record<string, unknown> }).params)

beforeEach(() => {
  get.mockReset()
  get.mockImplementation((url: string) =>
    Promise.resolve(
      url === '/maintenance/options'
        ? { data: { data: { statuses: [], types: [], technicians: [] } } }
        : {
            data: {
              data: [],
              meta: {
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 0,
                from: null,
                to: null,
              },
            },
          },
    ),
  )
})

describe('MaintenanceQueuePage — the dashboard filters', () => {
  it('sends due_within to the API', async () => {
    renderPage('/app/maintenance?due_within=7')

    await waitFor(() => expect(queueRequests().length).toBeGreaterThan(0))
    expect(queueRequests().at(-1)).toMatchObject({ due_within: '7' })
  })

  it('sends overdue and status as before', async () => {
    renderPage('/app/maintenance?overdue=1&status=in_progress')

    await waitFor(() => expect(queueRequests().length).toBeGreaterThan(0))
    expect(queueRequests().at(-1)).toMatchObject({ overdue: '1', status: 'in_progress' })
  })

  it('shows the window as the selected "Coming up" option, even a custom one', async () => {
    renderPage('/app/maintenance?due_within=21')

    const select = (await screen.findByRole('combobox', {
      name: /coming up/i,
    })) as HTMLSelectElement
    expect(select.value).toBe('21')
    expect(screen.getByRole('option', { name: 'Next 21 days' })).toBeInTheDocument()
  })
})

describe('MaintenanceQueuePage — empty states name the filter', () => {
  it.each([
    ['/app/maintenance?overdue=1', 'Nothing is overdue'],
    ['/app/maintenance?due_within=7', 'Nothing due in the next 7 days'],
    ['/app/maintenance?status=in_progress', 'Nothing in progress'],
    ['/app/maintenance?search=fan', 'No maintenance matches these filters'],
    ['/app/maintenance', 'No open maintenance'],
  ])('%s → "%s"', async (url, title) => {
    renderPage(url)

    expect(await screen.findByText(title)).toBeInTheDocument()
  })

  it('offers a way back to the whole queue only when a filter is active', async () => {
    renderPage('/app/maintenance?overdue=1')
    expect(
      await screen.findByRole('button', { name: 'Show all my maintenance' }),
    ).toBeInTheDocument()
  })
})
