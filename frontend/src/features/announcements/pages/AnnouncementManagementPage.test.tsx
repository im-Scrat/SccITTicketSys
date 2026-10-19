import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AnnouncementManagementPage from './AnnouncementManagementPage'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

const CREATED = {
  id: 'ann-1',
  title: 'Lab closed',
  content: 'Lab 2 is closed today.',
  audience: 'all',
  is_pinned: false,
  starts_at: null,
  ends_at: null,
  created_at: '2026-10-19T08:00:00Z',
  is_active: false,
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <AnnouncementManagementPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function compose(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: /new announcement/i }))
  await user.type(screen.getByLabelText(/^Title/), 'Lab closed')
  await user.type(screen.getByLabelText(/^Announcement/), 'Lab 2 is closed today.')
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  get.mockResolvedValue({
    data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } },
  })
})

describe('AnnouncementManagementPage — Publish from the form', () => {
  it('creates the announcement, then publishes it through the publish endpoint', async () => {
    post.mockImplementation((url: string) =>
      Promise.resolve({
        data: { data: url.endsWith('/publish') ? { ...CREATED, is_active: true } : CREATED },
      }),
    )
    const user = userEvent.setup()
    renderPage()

    await compose(user)
    await user.click(screen.getByRole('button', { name: /^publish$/i }))

    await waitFor(() => expect(post).toHaveBeenCalledTimes(2))
    expect(post.mock.calls[0][0]).toBe('/admin/announcements')
    // The store request carries no publication field (decision D7).
    expect(post.mock.calls[0][1]).not.toHaveProperty('is_active')
    expect(post.mock.calls[1][0]).toBe('/admin/announcements/ann-1/publish')
    // The form closes once saved, so a second press cannot create a copy.
    await waitFor(() => expect(screen.queryByLabelText(/^Title/)).not.toBeInTheDocument())
  })

  it('saves a draft without publishing from Save draft', async () => {
    post.mockResolvedValue({ data: { data: CREATED } })
    const user = userEvent.setup()
    renderPage()

    await compose(user)
    await user.click(screen.getByRole('button', { name: /^save draft$/i }))

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
    expect(post.mock.calls[0][0]).toBe('/admin/announcements')
  })

  it('keeps the saved draft and says so when publishing fails', async () => {
    post.mockImplementation((url: string) =>
      url.endsWith('/publish')
        ? Promise.reject(new Error('network down'))
        : Promise.resolve({ data: { data: CREATED } }),
    )
    const user = userEvent.setup()
    renderPage()

    await compose(user)
    await user.click(screen.getByRole('button', { name: /^publish$/i }))

    expect(await screen.findByText(/saved as a draft but could not be published/i)).toBeVisible()
    expect(post).toHaveBeenCalledTimes(2)
    expect(screen.queryByLabelText(/^Title/)).not.toBeInTheDocument()
  })

  it('keeps the form open with the reason when the save itself is refused', async () => {
    post.mockRejectedValue(
      Object.assign(new Error('422'), {
        isAxiosError: true,
        response: {
          status: 422,
          data: { message: 'Invalid', errors: { title: ['That title is already in use.'] } },
        },
      }),
    )
    const user = userEvent.setup()
    renderPage()

    await compose(user)
    await user.click(screen.getByRole('button', { name: /^publish$/i }))

    expect(await screen.findByText('That title is already in use.')).toBeVisible()
    // Nothing was saved, so nothing was published.
    expect(post).toHaveBeenCalledTimes(1)
  })
})
