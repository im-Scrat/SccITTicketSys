import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, type AxiosResponse } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { preparePhoto } from '@/lib/images'
import { TicketAttachments } from './TicketAttachments'

/**
 * The "Photos & files" uploader is where a reporter adds again a photo that
 * failed while reporting, so it must prepare a photo exactly as the report
 * form does (SDD DD-80) — re-sending the original would fail the same way.
 */

const get = vi.fn()
const post = vi.fn()

vi.mock('@/services/api', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    delete: vi.fn(),
  },
}))

vi.mock('@/lib/images', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/images')>()
  return { ...actual, preparePhoto: vi.fn() }
})

const originalMatchMedia = window.matchMedia

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  vi.mocked(preparePhoto).mockReset()
  globalThis.URL.createObjectURL = vi.fn(() => 'blob:preview')
  globalThis.URL.revokeObjectURL = vi.fn()

  get.mockImplementation((url: string) => {
    if (url === '/tickets/t-1/attachments') return Promise.resolve({ data: { data: [] } })
    if (url === '/tickets/options') {
      return Promise.resolve({
        data: {
          data: {
            categories: [],
            statuses: [],
            tags: [],
            priorities: [],
            technicians: [],
            uploads: { max_kb: 10_240 },
          },
        },
      })
    }
    return Promise.reject(new Error(`unexpected GET ${url}`))
  })
  post.mockResolvedValue({ data: { data: { id: 'att-1' } } })

  // A desktop: one "Add photos" button.
  window.matchMedia = vi.fn(() => ({
    matches: false,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia
})

afterEach(() => {
  window.matchMedia = originalMatchMedia
})

function renderAttachments(canManage = true) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <TicketAttachments ticketId="t-1" canManage={canManage} />
    </QueryClientProvider>,
  )
}

/** The photo list's polite live region, which is in the page before any photo is. */
const announcer = () => document.querySelector('p[aria-live="polite"]') as HTMLElement

/** The file inside the multipart body of the n-th POST. */
function uploaded(call = 0): File {
  const [url, body] = post.mock.calls[call] as [string, FormData]
  expect(url).toBe('/tickets/t-1/attachments')
  return body.get('file') as File
}

describe('TicketAttachments — adding photos', () => {
  it('prepares a photo before sending it, and sends the prepared file under its real name', async () => {
    const original = new File([new Uint8Array(6_000_000)], 'IMG_0042.heic', { type: 'image/jpeg' })
    const prepared = new File([new Uint8Array(900_000)], 'IMG_0042.jpg', { type: 'image/jpeg' })
    vi.mocked(preparePhoto).mockResolvedValue({ file: prepared, reencoded: true })
    renderAttachments()

    await userEvent.upload(await screen.findByTestId('photo-gallery-input'), original)

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
    expect(preparePhoto).toHaveBeenCalledWith(original)
    expect(uploaded()).toBe(prepared)
    expect(uploaded().name).toBe('IMG_0042.jpg')
  })

  it('sends several photos one after another', async () => {
    vi.mocked(preparePhoto).mockImplementation(async (file) => ({ file, reencoded: false }))
    renderAttachments()

    await userEvent.upload(await screen.findByTestId('photo-gallery-input'), [
      new File(['a'], 'a.jpg', { type: 'image/jpeg' }),
      new File(['b'], 'b.jpg', { type: 'image/jpeg' }),
    ])

    await waitFor(() => expect(post).toHaveBeenCalledTimes(2))
    expect(uploaded(0).name).toBe('a.jpg')
    expect(uploaded(1).name).toBe('b.jpg')
  })

  it('does not send a photo still over the size limit, and says why', async () => {
    vi.mocked(preparePhoto).mockImplementation(async (file) => ({ file, reencoded: false }))
    renderAttachments()
    // The limit comes from the options; wait for it before picking.
    await waitFor(() => expect(get).toHaveBeenCalledWith('/tickets/options'))

    await userEvent.upload(
      await screen.findByTestId('photo-gallery-input'),
      new File([new Uint8Array(11 * 1024 * 1024)], 'loop.gif', { type: 'image/gif' }),
    )

    const row = await screen.findByRole('listitem')
    expect(await within(row).findByText(/larger than 10 MB/i)).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })

  it('keeps a photo the server refused listed, with the server’s reason — and says so', async () => {
    vi.mocked(preparePhoto).mockImplementation(async (file) => ({ file, reencoded: false }))
    post.mockRejectedValueOnce(
      new AxiosError('Unprocessable', '422', undefined, undefined, {
        status: 422,
        data: { message: 'The file field must be a file of type: png, jpg.' },
      } as AxiosResponse),
    )
    renderAttachments()

    await userEvent.upload(
      await screen.findByTestId('photo-gallery-input'),
      new File(['a'], 'a.jpg', { type: 'image/jpeg' }),
    )

    const row = await screen.findByRole('listitem')
    expect(await within(row).findByText(/must be a file of type/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remove photo 1 (a.jpg)' })).toBeInTheDocument()
    expect(announcer()).toHaveTextContent(
      'Photo 1 (a.jpg) was not added: The file field must be a file of type: png, jpg.',
    )
  })

  it('says each photo that arrived', async () => {
    vi.mocked(preparePhoto).mockImplementation(async (file) => ({ file, reencoded: false }))
    renderAttachments()

    await userEvent.upload(
      await screen.findByTestId('photo-gallery-input'),
      new File(['a'], 'a.jpg', { type: 'image/jpeg' }),
    )

    await waitFor(() => expect(announcer()).toHaveTextContent('Photo 1 (a.jpg) uploaded.'))
    expect(screen.queryByRole('listitem')).not.toBeInTheDocument()
  })

  it('words the hourly upload limit for people, and sends nothing more from that pick', async () => {
    vi.mocked(preparePhoto).mockImplementation(async (file) => ({ file, reencoded: false }))
    post.mockRejectedValue(
      new AxiosError('Too Many Requests', '429', undefined, undefined, {
        status: 429,
        data: { message: 'Too Many Attempts.' },
      } as AxiosResponse),
    )
    renderAttachments()

    await userEvent.upload(await screen.findByTestId('photo-gallery-input'), [
      new File(['a'], 'a.jpg', { type: 'image/jpeg' }),
      new File(['b'], 'b.jpg', { type: 'image/jpeg' }),
      new File(['c'], 'c.jpg', { type: 'image/jpeg' }),
    ])

    await waitFor(() =>
      expect(
        screen.getAllByText('Too many photos were sent in the last hour — try again later.'),
      ).toHaveLength(3),
    )
    expect(post).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('Too Many Attempts.')).not.toBeInTheDocument()
  })

  it('still sends a photo that was being prepared when the tab was left', async () => {
    let finish!: () => void
    const prepared = new File(['a'], 'IMG_0042.jpg', { type: 'image/jpeg' })
    vi.mocked(preparePhoto).mockReturnValue(
      new Promise((resolve) => {
        finish = () => resolve({ file: prepared, reencoded: true })
      }),
    )
    const { unmount } = renderAttachments()

    await userEvent.upload(
      await screen.findByTestId('photo-gallery-input'),
      new File(['a'], 'IMG_0042.heic', { type: 'image/jpeg' }),
    )
    await waitFor(() => expect(preparePhoto).toHaveBeenCalled())
    unmount()
    finish()

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
    expect(uploaded()).toBe(prepared)
  })
})

describe('TicketAttachments — adding a file', () => {
  it('sends a PDF as picked, through its own button', async () => {
    renderAttachments()

    expect(await screen.findByRole('button', { name: 'Add a file' })).toBeInTheDocument()
    const pdf = new File(['%PDF'], 'event-log.pdf', { type: 'application/pdf' })
    await userEvent.upload(screen.getByTestId('document-input'), pdf)

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
    expect(uploaded()).toBe(pdf)
    expect(preparePhoto).not.toHaveBeenCalled()
  })

  it('puts keyboard focus on real buttons, never on a hidden input', async () => {
    renderAttachments()

    const document = await screen.findByTestId('document-input')
    expect(document).toHaveAttribute('tabindex', '-1')
    expect(document).toHaveAttribute('aria-hidden', 'true')
    expect(screen.getByTestId('photo-gallery-input')).toHaveAttribute('tabindex', '-1')

    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'Add photos' })).toHaveFocus()
    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'Add a file' })).toHaveFocus()
  })

  it('offers nothing to add for someone who cannot attach', async () => {
    renderAttachments(false)

    expect(await screen.findByText('No photos or files yet')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add photos' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add a file' })).not.toBeInTheDocument()
  })
})
