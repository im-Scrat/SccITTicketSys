import { act, renderHook, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { preparePhoto, UnsupportedPhotoError } from '@/lib/images'
import { type PhotoDraft, type ReadyPhotos, usePreparedPhotos } from './usePreparedPhotos'

vi.mock('@/lib/images', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/images')>()
  return { ...actual, preparePhoto: vi.fn() }
})

const prepare = vi.mocked(preparePhoto)
let urlCount = 0

function photo(name: string, bytes = 1024, type = 'image/jpeg') {
  return new File([new Uint8Array(bytes)], name, { type })
}

/** A preparation the test finishes by hand, to watch the queue's order. */
function deferred() {
  let resolve!: (file: File) => void
  const promise = new Promise<{ file: File; reencoded: boolean }>((done) => {
    resolve = (file) => done({ file, reencoded: false })
  })
  return { promise, resolve }
}

beforeEach(() => {
  prepare.mockReset()
  prepare.mockImplementation(async (file) => ({ file, reencoded: false }))
  urlCount = 0
  globalThis.URL.createObjectURL = vi.fn(() => `blob:preview-${++urlCount}`)
  globalThis.URL.revokeObjectURL = vi.fn()
})

describe('usePreparedPhotos', () => {
  it('prepares photos one at a time, in the order they were picked', async () => {
    const first = deferred()
    const second = deferred()
    prepare.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))
    act(() => void result.current.add([photo('a.jpg'), photo('b.jpg')]))

    await waitFor(() => expect(prepare).toHaveBeenCalledTimes(1))
    expect(result.current.preparing).toBe(true)

    await act(async () => first.resolve(photo('a.jpg')))
    await waitFor(() => expect(prepare).toHaveBeenCalledTimes(2))

    await act(async () => second.resolve(photo('b.jpg')))
    await waitFor(() => expect(result.current.preparing).toBe(false))
    expect(result.current.drafts.map((draft) => draft.status)).toEqual(['ready', 'ready'])
  })

  it('previews the prepared file, not the original', async () => {
    const prepared = photo('a.jpg', 10)
    prepare.mockResolvedValueOnce({ file: prepared, reencoded: true })

    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))
    await act(() => result.current.add([photo('a.jpg', 5_000_000)]))

    expect(URL.createObjectURL).toHaveBeenCalledWith(prepared)
    expect(result.current.drafts[0]).toMatchObject({
      status: 'ready',
      file: prepared,
      previewUrl: 'blob:preview-1',
    })
  })

  it('takes no more than the cap, and says how many were left out', async () => {
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))
    const seven = Array.from({ length: 7 }, (_, index) => photo(`p${index}.jpg`))

    await act(() => result.current.add(seven))

    expect(result.current.drafts).toHaveLength(5)
    expect(result.current.count).toBe(5)
    expect(result.current.atLimit).toBe(true)
    expect(result.current.notice).toMatch(/up to 5 photos, so 2 were not added/i)
  })

  it('refuses a photo still over the server limit once prepared', async () => {
    const { result } = renderHook(() => usePreparedPhotos({ max: 5, maxKb: 10_240 }))

    await act(() => result.current.add([photo('huge.gif', 11 * 1024 * 1024, 'image/gif')]))

    expect(result.current.drafts[0].status).toBe('rejected')
    expect(result.current.drafts[0].error).toMatch(/larger than 10 MB/i)
    expect(URL.createObjectURL).not.toHaveBeenCalled()
    // A refused photo does not use up a place.
    expect(result.current.count).toBe(0)
  })

  it('refuses a file that is not a photo without trying to prepare it', async () => {
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))

    await act(() => result.current.add([photo('log.pdf', 100, 'application/pdf')]))

    expect(prepare).not.toHaveBeenCalled()
    expect(result.current.drafts[0]).toMatchObject({ status: 'rejected' })
    expect(result.current.drafts[0].error).toMatch(/not a photo/i)
  })

  it('shows the reason a photo cannot be used', async () => {
    prepare.mockRejectedValueOnce(new UnsupportedPhotoError())
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))

    await act(() => result.current.add([photo('IMG_0001.HEIC', 100, 'image/heic')]))

    expect(result.current.drafts[0].error).toMatch(/format cannot be used/i)
  })

  it('revokes a preview when its photo is removed', async () => {
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))
    await act(() => result.current.add([photo('a.jpg'), photo('b.jpg')]))

    act(() => result.current.remove(result.current.drafts[0].id))

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
    expect(URL.revokeObjectURL).not.toHaveBeenCalledWith('blob:preview-2')
    expect(result.current.drafts.map((draft) => draft.name)).toEqual(['b.jpg'])
  })

  it('revokes every preview when the surface goes away', async () => {
    const { result, unmount } = renderHook(() => usePreparedPhotos({ max: 5 }))
    await act(() => result.current.add([photo('a.jpg'), photo('b.jpg')]))

    unmount()

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-2')
  })

  it('makes no preview for a photo removed while it was still being prepared', async () => {
    const pending = deferred()
    prepare.mockReturnValueOnce(pending.promise)
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))

    act(() => void result.current.add([photo('a.jpg')]))
    await waitFor(() => expect(prepare).toHaveBeenCalled())
    act(() => result.current.remove(result.current.drafts[0].id))
    await act(async () => pending.resolve(photo('a.jpg')))

    expect(URL.createObjectURL).not.toHaveBeenCalled()
    expect(result.current.drafts).toEqual([])
  })

  it('still readies a photo, without a preview, when the surface was left before it finished', async () => {
    // The Photos & files tab is unmounted on every tab switch while its upload
    // chain waits on these photos: leaving must not drop them.
    const pending = deferred()
    prepare.mockReturnValueOnce(pending.promise)
    const { result, unmount } = renderHook(() => usePreparedPhotos({ max: 5 }))

    let settled!: Promise<PhotoDraft[]>
    act(() => void (settled = result.current.add([photo('a.jpg'), photo('b.jpg')])))
    await waitFor(() => expect(prepare).toHaveBeenCalledTimes(1))
    unmount()
    await act(async () => pending.resolve(photo('a-prepared.jpg')))

    // Both finish — the second had not even started when the surface was left.
    const drafts = await settled
    expect(prepare).toHaveBeenCalledTimes(2)
    expect(drafts.map((draft) => [draft.status, draft.file?.name, draft.previewUrl])).toEqual([
      ['ready', 'a-prepared.jpg', null],
      ['ready', 'b.jpg', null],
    ])
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })

  it('hands over the ready photos, in order, once nothing is still being prepared', async () => {
    const slow = deferred()
    prepare
      .mockImplementationOnce(async (file) => ({ file, reencoded: false }))
      .mockReturnValueOnce(slow.promise)
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))

    act(() => void result.current.add([photo('a.jpg'), photo('b.jpg')]))
    let handed: ReadyPhotos | null = null
    const ready = result.current.whenReady().then((photos) => (handed = photos))

    await waitFor(() => expect(prepare).toHaveBeenCalledTimes(2))
    expect(handed).toBeNull()

    await act(async () => slow.resolve(photo('b-prepared.jpg')))
    await ready
    expect(handed).toEqual({
      ready: [
        { file: expect.objectContaining({ name: 'a.jpg' }), index: 1, name: 'a.jpg' },
        // The name the list showed, not the prepared file's.
        { file: expect.objectContaining({ name: 'b-prepared.jpg' }), index: 2, name: 'b.jpg' },
      ],
      refusedWhileWaiting: [],
    })
  })

  it('numbers the ready photos as the list does, counting the refused ones still listed', async () => {
    prepare.mockImplementation(async (file) => {
      if (file.name === 'b.jpg') throw new UnsupportedPhotoError()
      return { file, reencoded: false }
    })
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))

    await act(() => result.current.add([photo('a.jpg'), photo('b.jpg'), photo('c.jpg')]))
    const { ready, refusedWhileWaiting } = await result.current.whenReady()

    expect(ready.map(({ index, name }) => [index, name])).toEqual([
      [1, 'a.jpg'],
      [3, 'c.jpg'],
    ])
    // Refused before anyone was waiting: already shown in the list, not handed back.
    expect(refusedWhileWaiting).toEqual([])
  })

  it('hands back a photo refused while the caller was waiting for it', async () => {
    const slow = deferred()
    prepare
      .mockImplementationOnce(async (file) => ({ file, reencoded: false }))
      .mockReturnValueOnce(slow.promise.then(() => Promise.reject(new UnsupportedPhotoError())))
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))

    act(() => void result.current.add([photo('a.jpg'), photo('IMG_7.heic', 100, 'image/heic')]))
    await waitFor(() => expect(prepare).toHaveBeenCalledTimes(2))
    const waiting = result.current.whenReady()

    await act(async () => slow.resolve(photo('ignored.jpg')))
    const { ready, refusedWhileWaiting } = await waiting

    expect(ready.map(({ index }) => index)).toEqual([1])
    expect(refusedWhileWaiting).toEqual([
      { index: 2, name: 'IMG_7.heic', reason: expect.stringMatching(/format cannot be used/i) },
    ])
  })
})

describe('usePreparedPhotos — what a screen reader hears', () => {
  it('says which photo was refused, and why', async () => {
    const { result } = renderHook(() => usePreparedPhotos({ max: 5, maxKb: 10_240 }))

    await act(() => result.current.add([photo('a.jpg')]))
    await act(() => result.current.add([photo('huge.gif', 11 * 1024 * 1024, 'image/gif')]))

    expect(result.current.announcement?.text).toMatch(
      /^Photo 2 \(huge\.gif\) was not added: This photo is larger than 10 MB/,
    )
  })

  it('says every not-a-photo of one pick at once', async () => {
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))

    await act(() =>
      result.current.add([
        photo('log.pdf', 100, 'application/pdf'),
        photo('notes.txt', 100, 'text/plain'),
      ]),
    )

    expect(result.current.announcement?.text).toBe(
      'Photo 1 (log.pdf) was not added: That file is not a photo. Choose a JPEG, PNG, WebP or GIF image. ' +
        'Photo 2 (notes.txt) was not added: That file is not a photo. Choose a JPEG, PNG, WebP or GIF image.',
    )
  })

  it('says when a caller’s upload is refused, or arrives — twice the same words still count twice', async () => {
    const { result } = renderHook(() => usePreparedPhotos({ max: 5 }))
    await act(() => result.current.add([photo('image.jpg'), photo('image.jpg')]))
    const [first, second] = result.current.drafts

    act(() => result.current.reject(first.id, 'Too many photos were sent in the last hour.'))
    expect(result.current.announcement?.text).toBe(
      'Photo 1 (image.jpg) was not added: Too many photos were sent in the last hour.',
    )

    act(() => result.current.sent(second.id))
    expect(result.current.announcement?.text).toBe('Photo 2 (image.jpg) uploaded.')
    expect(result.current.drafts.map((draft) => draft.id)).toEqual([first.id])

    // Two iPhone photos sent in turn are each "Photo 1 (image.jpg)" when they go.
    act(() => result.current.remove(first.id, { focus: false }))
    await act(() => result.current.add([photo('image.jpg'), photo('image.jpg')]))
    act(() => result.current.sent(result.current.drafts[0].id))
    const once = result.current.announcement
    act(() => result.current.sent(result.current.drafts[0].id))
    expect(result.current.announcement?.text).toBe('Photo 1 (image.jpg) uploaded.')
    expect(result.current.announcement?.text).toBe(once?.text)
    expect(result.current.announcement?.id).not.toBe(once?.id)
  })
})
