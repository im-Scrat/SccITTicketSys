import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { usePreparedPhotos } from '@/hooks/usePreparedPhotos'
import { PHOTO_ACCEPT, preparePhoto } from '@/lib/images'
import { ReportPhotos } from './ReportPhotos'

// The pipeline itself is covered in lib/images.test.ts; here it hands each
// photo back unchanged, as it does for a small one.
vi.mock('@/lib/images', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/images')>()
  return { ...actual, preparePhoto: vi.fn() }
})

const originalMatchMedia = window.matchMedia
let urlCount = 0

beforeEach(() => {
  vi.mocked(preparePhoto).mockReset()
  vi.mocked(preparePhoto).mockImplementation(async (file) => ({ file, reencoded: false }))
  urlCount = 0
  globalThis.URL.createObjectURL = vi.fn(() => `blob:preview-${++urlCount}`)
  globalThis.URL.revokeObjectURL = vi.fn()

  window.matchMedia = vi.fn((query: string) => ({
    matches: query === '(pointer: coarse)',
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia
})

afterEach(() => {
  window.matchMedia = originalMatchMedia
})

function Harness({ maxKb = 10_240 }: { maxKb?: number }) {
  const photos = usePreparedPhotos({
    max: 5,
    maxKb,
    fallbackFocus: () => document.getElementById('photo-picker'),
  })
  return <ReportPhotos photos={photos} pickerId="photo-picker" />
}

const jpeg = (name: string, bytes = 2048) =>
  new File([new Uint8Array(bytes)], name, { type: 'image/jpeg' })

const gallery = () => screen.getByTestId('photo-gallery-input') as HTMLInputElement
const count = () => screen.getByText(/of 5 photos?/)
/** The photo list's own polite region — the one that is not the count. */
const announcerOf = () =>
  Array.from(document.querySelectorAll('[aria-live="polite"]')).find(
    (region) => region !== count(),
  ) as HTMLElement

describe('ReportPhotos', () => {
  it('offers the camera and the gallery, filtered to the photo types the server accepts', () => {
    render(<Harness />)

    const camera = screen.getByTestId('photo-camera-input')
    expect(camera).toHaveAttribute('capture', 'environment')
    expect(camera).toHaveAttribute('accept', PHOTO_ACCEPT)
    expect(gallery()).toHaveAttribute('multiple')
    expect(gallery()).toHaveAttribute('accept', PHOTO_ACCEPT)
    expect(count()).toHaveTextContent('0 of 5 photos')
  })

  it('shows a numbered thumbnail for each photo and a live count', async () => {
    render(<Harness />)

    await userEvent.upload(gallery(), [jpeg('image.jpg'), jpeg('image.jpg')])

    expect(await screen.findByRole('img', { name: 'Photo 1: image.jpg' })).toHaveAttribute(
      'src',
      'blob:preview-1',
    )
    expect(screen.getByRole('img', { name: 'Photo 2: image.jpg' })).toBeInTheDocument()
    expect(count()).toHaveTextContent('2 of 5 photos')
    expect(count()).toHaveAttribute('aria-live', 'polite')
  })

  it('refuses a file that is not a photo, inline, and says so', async () => {
    const user = userEvent.setup({ applyAccept: false })
    render(<Harness />)
    // The live region is in the page before anything happens to it.
    const announcer = announcerOf()
    expect(announcer).toBeEmptyDOMElement()

    await user.upload(gallery(), new File(['%PDF'], 'invoice.pdf', { type: 'application/pdf' }))

    const row = await screen.findByRole('listitem')
    expect(within(row).getByText(/that file is not a photo/i)).toBeInTheDocument()
    expect(announcer).toHaveTextContent(
      'Photo 1 (invoice.pdf) was not added: That file is not a photo.',
    )
    expect(count()).toHaveTextContent('0 of 5 photos')
  })

  it('refuses an 11 MB GIF for its size — a GIF is never shrunk — and says so', async () => {
    render(<Harness maxKb={10_240} />)

    await userEvent.upload(
      gallery(),
      new File([new Uint8Array(11 * 1024 * 1024)], 'loop.gif', { type: 'image/gif' }),
    )

    const row = await screen.findByRole('listitem')
    expect(await within(row).findByText(/larger than 10 MB/i)).toBeInTheDocument()
    expect(announcerOf()).toHaveTextContent(/^Photo 1 \(loop\.gif\) was not added: .*10 MB/)
    expect(count()).toHaveTextContent('0 of 5 photos')
  })

  it('keeps five of seven and says so; the picker stays usable and refuses more', async () => {
    render(<Harness />)
    // The notice is always in the page, empty, so a change to it is read out.
    expect(screen.getByRole('status')).toBeEmptyDOMElement()

    await userEvent.upload(
      gallery(),
      Array.from({ length: 7 }, (_, index) => jpeg(`p${index + 1}.jpg`)),
    )

    await waitFor(() => expect(screen.getAllByRole('img')).toHaveLength(5))
    expect(screen.getByRole('status')).toHaveTextContent(/so 2 were not added/i)
    expect(count()).toHaveTextContent('5 of 5 photos')

    // Not disabled: focus comes back to it from the file dialog, and a
    // disabled button would drop it to the page body.
    const picker = screen.getByRole('button', { name: 'Choose from gallery' })
    expect(picker).toBeEnabled()
    await userEvent.upload(gallery(), jpeg('p8.jpg'))
    expect(screen.getByRole('status')).toHaveTextContent(
      'You can add up to 5 photos. Remove one to add another.',
    )
    expect(screen.getAllByRole('img')).toHaveLength(5)
  })

  it('removes one photo, revokes only its preview, and moves focus to the next remove button', async () => {
    render(<Harness />)
    await userEvent.upload(gallery(), [jpeg('a.jpg'), jpeg('b.jpg')])
    await screen.findByRole('img', { name: 'Photo 2: b.jpg' })

    await userEvent.click(screen.getByRole('button', { name: 'Remove photo 1 (a.jpg)' }))

    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
    // b.jpg is photo 1 now.
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Remove photo 1 (b.jpg)' })).toHaveFocus(),
    )
  })

  it('returns focus to the picker when the last photo is removed', async () => {
    render(<Harness />)
    await userEvent.upload(gallery(), [jpeg('a.jpg')])
    await screen.findByRole('img', { name: 'Photo 1: a.jpg' })

    await userEvent.click(screen.getByRole('button', { name: 'Remove photo 1 (a.jpg)' }))

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Choose from gallery' })).toHaveFocus(),
    )
  })

  it('revokes every preview when the form is left', async () => {
    const { unmount } = render(<Harness />)
    await userEvent.upload(gallery(), [jpeg('a.jpg'), jpeg('b.jpg')])
    await screen.findByRole('img', { name: 'Photo 2: b.jpg' })

    unmount()

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-2')
  })

  it('names each remove button after its photo', async () => {
    render(<Harness />)
    await userEvent.upload(gallery(), [jpeg('image.jpg'), jpeg('image.jpg')])

    const list = (await screen.findAllByRole('listitem')).map((item) => within(item))
    expect(list[0].getByRole('button')).toHaveAccessibleName('Remove photo 1 (image.jpg)')
    expect(list[1].getByRole('button')).toHaveAccessibleName('Remove photo 2 (image.jpg)')
  })
})
