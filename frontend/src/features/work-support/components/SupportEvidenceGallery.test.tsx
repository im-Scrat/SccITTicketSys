import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SupportEvidenceGallery } from './SupportEvidenceGallery'
import type { SupportRequestAttachment } from '../types'

const fetchAttachmentBlob = vi.hoisted(() => vi.fn())

vi.mock('../api/workSupportApi', () => ({ fetchAttachmentBlob }))

/**
 * Viewing evidence attached to a support request (SRS FR-WSR-003/005).
 *
 * Two properties, and neither is styling:
 *
 * **The bytes never come from a URL.** Files live on a private disk, so every
 * preview must pull through the authenticated client and hold an object URL.
 * A test that only checked "an image renders" would pass just as well if
 * someone pointed an `<img src>` at the API route — which would break document
 * downloads and leak the path — so this asserts the *source shape* directly.
 *
 * **The accessibility tree.** Every query is by role and accessible name, so the
 * gallery passes only if a keyboard and screen-reader user can open the same
 * evidence a mouse user can.
 */
function attachment(overrides: Partial<SupportRequestAttachment> = {}): SupportRequestAttachment {
  return {
    id: 'att-1',
    filename: 'burnt-psu.jpg',
    kind: 'image',
    size: 2048,
    caption: null,
    ...overrides,
  }
}

beforeEach(() => {
  fetchAttachmentBlob.mockReset()
  fetchAttachmentBlob.mockResolvedValue(new Blob(['bytes'], { type: 'image/jpeg' }))

  // jsdom does not implement the object-URL API.
  globalThis.URL.createObjectURL = vi.fn(() => 'blob:mock-object-url')
  globalThis.URL.revokeObjectURL = vi.fn()
})

describe('when there is nothing attached', () => {
  it('renders nothing at all', () => {
    const { container } = render(<SupportEvidenceGallery requestId="req-1" attachments={[]} />)

    expect(container).toBeEmptyDOMElement()
  })
})

describe('how the bytes are fetched', () => {
  it('pulls each image through the authorized client, never from a URL', async () => {
    render(<SupportEvidenceGallery requestId="req-1" attachments={[attachment()]} />)

    await waitFor(() => expect(fetchAttachmentBlob).toHaveBeenCalledWith('req-1', 'att-1'))

    const image = await screen.findByRole('img', { name: /burnt-psu\.jpg/i })

    // An object URL, not the API route and certainly not a storage path.
    expect(image.getAttribute('src')).toBe('blob:mock-object-url')
    expect(image.getAttribute('src')).not.toContain('/api/')
    expect(image.getAttribute('src')).not.toContain('work-support/')
  })

  it('revokes the object URL when it unmounts', async () => {
    const { unmount } = render(
      <SupportEvidenceGallery requestId="req-1" attachments={[attachment()]} />,
    )

    await screen.findByRole('img', { name: /burnt-psu\.jpg/i })
    unmount()

    expect(globalThis.URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-object-url')
  })

  it('fetches nothing for a document and shows an icon instead', () => {
    render(
      <SupportEvidenceGallery
        requestId="req-1"
        attachments={[attachment({ kind: 'document', filename: 'quote.pdf' })]}
      />,
    )

    // A PDF is served as a download, so there is nothing to preview and no
    // request to make.
    expect(fetchAttachmentBlob).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: /open quote\.pdf/i })).toBeInTheDocument()
  })
})

describe('the broken-attachment state', () => {
  it('says the preview is unavailable rather than showing an empty box', async () => {
    fetchAttachmentBlob.mockRejectedValueOnce(new Error('gone'))

    render(<SupportEvidenceGallery requestId="req-1" attachments={[attachment()]} />)

    expect(await screen.findByText(/preview unavailable/i)).toBeInTheDocument()
  })
})

describe('the lightbox', () => {
  it('opens from the keyboard and names the evidence', async () => {
    const user = userEvent.setup()
    render(<SupportEvidenceGallery requestId="req-1" attachments={[attachment()]} />)

    const trigger = screen.getByRole('button', { name: /open burnt-psu\.jpg/i })

    await user.tab()
    expect(trigger).toHaveFocus()

    await user.keyboard('{Enter}')

    expect(await screen.findByRole('dialog')).toBeInTheDocument()
  })

  it('closes on Escape and returns focus to what opened it', async () => {
    const user = userEvent.setup()
    render(<SupportEvidenceGallery requestId="req-1" attachments={[attachment()]} />)

    const trigger = screen.getByRole('button', { name: /open burnt-psu\.jpg/i })

    await user.click(trigger)
    await screen.findByRole('dialog')

    await user.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    await waitFor(() => expect(trigger).toHaveFocus())
  })

  it('uses the technicians caption as the alt text when there is one', async () => {
    render(
      <SupportEvidenceGallery
        requestId="req-1"
        attachments={[attachment({ caption: 'Scorch marks on the PSU casing' })]}
      />,
    )

    expect(
      await screen.findByRole('img', { name: /scorch marks on the psu casing/i }),
    ).toBeInTheDocument()
  })
})
