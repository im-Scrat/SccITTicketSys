import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  JPEG_QUALITY,
  photoKind,
  preparePhoto,
  REENCODE_OVER_BYTES,
  UnsupportedPhotoError,
  withExtension,
} from './images'

/*
 * jsdom decodes no images and draws nothing, so the browser's side of the
 * pipeline is stood in for: `createImageBitmap` reports a size, the canvas
 * records what was drawn and hands back a JPEG blob. What is under test is the
 * pipeline's decisions — when to redraw, at what size, under what name, and
 * what to do when a step fails. Orientation cannot be tested here at all; the
 * release checklist covers it on real phones.
 */

const drawImage = vi.fn()
const fillRect = vi.fn()
const originalGetContext = HTMLCanvasElement.prototype.getContext
const originalToBlob = HTMLCanvasElement.prototype.toBlob
const originalDecode = HTMLImageElement.prototype.decode

function bitmap(width: number, height: number) {
  return { width, height, close: vi.fn() }
}

function file(name: string, type: string, bytes = 1024): File {
  return new File([new Uint8Array(bytes)], name, { type, lastModified: 1_700_000_000_000 })
}

beforeEach(() => {
  drawImage.mockReset()
  fillRect.mockReset()

  globalThis.URL.createObjectURL = vi.fn(() => 'blob:decode')
  globalThis.URL.revokeObjectURL = vi.fn()

  HTMLCanvasElement.prototype.getContext = vi.fn(function getContext() {
    return { drawImage, fillRect, fillStyle: '' }
  }) as unknown as typeof HTMLCanvasElement.prototype.getContext

  HTMLCanvasElement.prototype.toBlob = vi.fn((callback: BlobCallback, type?: string) => {
    callback(new Blob(['re-encoded'], { type }))
  })

  // An undecodable file by default; tests that need a decode say so.
  globalThis.createImageBitmap = vi.fn(async () => {
    throw new Error('undecodable')
  }) as unknown as typeof createImageBitmap
  HTMLImageElement.prototype.decode = vi.fn(async () => {
    throw new Error('undecodable')
  })
})

afterEach(() => {
  HTMLCanvasElement.prototype.getContext = originalGetContext
  HTMLCanvasElement.prototype.toBlob = originalToBlob
  HTMLImageElement.prototype.decode = originalDecode
})

describe('withExtension', () => {
  it('adds the extension of the real type to a name that has none', () => {
    expect(withExtension('IMG_0042', 'image/jpeg')).toBe('IMG_0042.jpg')
  })

  it('replaces an extension that does not match the bytes', () => {
    expect(withExtension('IMG_0042.heic', 'image/jpeg')).toBe('IMG_0042.jpg')
    expect(withExtension('scan.jpg', 'image/png')).toBe('scan.png')
  })

  it('keeps an extension that is already right, however it is spelled', () => {
    expect(withExtension('holiday.jpeg', 'image/jpeg')).toBe('holiday.jpeg')
    expect(withExtension('Screen.PNG', 'image/png')).toBe('Screen.PNG')
  })

  it('names a nameless file', () => {
    expect(withExtension('', 'image/jpeg')).toBe('photo.jpg')
  })

  it('renames a .jfif JPEG — a JPEG, but not an extension the server accepts', () => {
    expect(withExtension('a.jfif', 'image/jpeg')).toBe('a.jpg')
    expect(withExtension('Projector.JFIF', 'image/jpeg')).toBe('Projector.jpg')
  })
})

describe('photoKind', () => {
  it('trusts a photo MIME type first', () => {
    expect(photoKind({ name: 'IMG_0042.heic', type: 'image/jpeg' })).toBe('image/jpeg')
  })

  it('falls back to the extension when the browser gave no type', () => {
    expect(photoKind({ name: 'diagram.webp', type: '' })).toBe('image/webp')
    expect(photoKind({ name: 'IMG_0042.HEIC', type: '' })).toBe('heic')
  })

  it('knows HEIC by its type', () => {
    expect(photoKind({ name: 'photo', type: 'image/heic' })).toBe('heic')
  })

  it('leaves an untyped, unnamed file to be decoded rather than refused', () => {
    expect(photoKind({ name: '1000012345', type: '' })).toBe('unknown')
    expect(photoKind({ name: 'blob', type: 'application/octet-stream' })).toBe('unknown')
    expect(photoKind({ name: 'scan.bmp', type: 'image/bmp' })).toBe('unknown')
  })

  it('refuses a declared non-image', () => {
    expect(photoKind({ name: 'log.pdf', type: 'application/pdf' })).toBe('not-image')
  })
})

describe('preparePhoto', () => {
  it('shrinks a large phone photo to 2560 px on its long edge, as a JPEG under its real name', async () => {
    const decoded = bitmap(4000, 3000)
    vi.mocked(createImageBitmap).mockResolvedValueOnce(decoded as unknown as ImageBitmap)

    const result = await preparePhoto(file('IMG_0042.heic', 'image/jpeg'))

    expect(result.reencoded).toBe(true)
    expect(result.file.type).toBe('image/jpeg')
    expect(result.file.name).toBe('IMG_0042.jpg')
    expect(drawImage).toHaveBeenCalledWith(decoded, 0, 0, 2560, 1920)
    expect(HTMLCanvasElement.prototype.toBlob).toHaveBeenCalledWith(
      expect.any(Function),
      'image/jpeg',
      JPEG_QUALITY,
    )
    // The decode asks for the orientation tag to be applied.
    expect(createImageBitmap).toHaveBeenCalledWith(expect.any(File), {
      imageOrientation: 'from-image',
    })
  })

  it('releases the decoded photo and empties the shared canvas afterwards', async () => {
    const decoded = bitmap(4000, 3000)
    vi.mocked(createImageBitmap).mockResolvedValueOnce(decoded as unknown as ImageBitmap)

    await preparePhoto(file('IMG_1.jpg', 'image/jpeg'))

    expect(decoded.close).toHaveBeenCalledTimes(1)
    // `mock.contexts` holds the canvas each call was made on.
    const canvas = vi.mocked(HTMLCanvasElement.prototype.toBlob).mock
      .contexts[0] as HTMLCanvasElement
    expect(canvas.width).toBe(0)
    expect(canvas.height).toBe(0)
  })

  it('returns a small photo untouched — the same object when its name is already right', async () => {
    const decoded = bitmap(800, 600)
    vi.mocked(createImageBitmap).mockResolvedValueOnce(decoded as unknown as ImageBitmap)
    const original = file('shot.png', 'image/png')

    const result = await preparePhoto(original)

    expect(result).toEqual({ file: original, reencoded: false })
    expect(result.file).toBe(original)
    expect(drawImage).not.toHaveBeenCalled()
    expect(decoded.close).toHaveBeenCalledTimes(1)
  })

  it('renames a small photo whose name does not say what it is, without touching its bytes', async () => {
    vi.mocked(createImageBitmap).mockResolvedValueOnce(bitmap(640, 480) as unknown as ImageBitmap)
    const original = file('Screenshot_20261016', 'image/png', 2048)

    const result = await preparePhoto(original)

    expect(result.reencoded).toBe(false)
    expect(result.file).not.toBe(original)
    expect(result.file.name).toBe('Screenshot_20261016.png')
    expect(result.file.type).toBe('image/png')
    expect(result.file.size).toBe(2048)
  })

  it('sends a small .jfif under a .jpg name, bytes untouched', async () => {
    vi.mocked(createImageBitmap).mockResolvedValueOnce(bitmap(800, 600) as unknown as ImageBitmap)
    const original = file('projector.jfif', 'image/jpeg', 2048)

    const result = await preparePhoto(original)

    expect(result.reencoded).toBe(false)
    expect(result.file.name).toBe('projector.jpg')
    expect(result.file.type).toBe('image/jpeg')
    expect(result.file.size).toBe(2048)
    // Still known as a JPEG by its extension when the browser gives no type.
    expect(photoKind({ name: 'projector.jfif', type: '' })).toBe('image/jpeg')
  })

  it('redraws a modest-sized photo whose file is over 4 MB', async () => {
    vi.mocked(createImageBitmap).mockResolvedValueOnce(bitmap(2000, 1500) as unknown as ImageBitmap)

    const result = await preparePhoto(file('export.png', 'image/png', REENCODE_OVER_BYTES + 1))

    expect(result.reencoded).toBe(true)
    expect(result.file.name).toBe('export.jpg')
    expect(drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 2000, 1500)
  })

  it('never redraws a GIF, however large — it may be animated', async () => {
    const gif = file('loop', 'image/gif', REENCODE_OVER_BYTES * 2)

    const result = await preparePhoto(gif)

    expect(createImageBitmap).not.toHaveBeenCalled()
    expect(result.reencoded).toBe(false)
    expect(result.file.name).toBe('loop.gif')
  })

  it('sends the original when a JPEG cannot be decoded at all', async () => {
    const original = file('IMG_0042.jpg', 'image/jpeg')

    const result = await preparePhoto(original)

    expect(result).toEqual({ file: original, reencoded: false })
  })

  it('sends the original when the canvas refuses to draw', async () => {
    vi.mocked(createImageBitmap).mockResolvedValueOnce(bitmap(4000, 3000) as unknown as ImageBitmap)
    HTMLCanvasElement.prototype.getContext = vi.fn(() => null) as never
    const original = file('IMG_0042.jpg', 'image/jpeg')

    const result = await preparePhoto(original)

    expect(result).toEqual({ file: original, reencoded: false })
  })

  it('decodes through an <img> when createImageBitmap rejects the orientation option', async () => {
    HTMLImageElement.prototype.decode = vi.fn(async () => undefined)
    const width = vi.spyOn(HTMLImageElement.prototype, 'naturalWidth', 'get').mockReturnValue(3000)
    const height = vi
      .spyOn(HTMLImageElement.prototype, 'naturalHeight', 'get')
      .mockReturnValue(4000)

    try {
      const result = await preparePhoto(file('portrait.jpg', 'image/jpeg'))

      expect(result.reencoded).toBe(true)
      expect(drawImage).toHaveBeenCalledWith(expect.any(HTMLImageElement), 0, 0, 1920, 2560)
      // The temporary URL the <img> read from is let go.
      expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:decode')
    } finally {
      width.mockRestore()
      height.mockRestore()
    }
  })

  it('turns a HEIC this browser can decode into a JPEG', async () => {
    vi.mocked(createImageBitmap).mockResolvedValueOnce(bitmap(1200, 900) as unknown as ImageBitmap)

    const result = await preparePhoto(file('IMG_0042.HEIC', 'image/heic'))

    expect(result.reencoded).toBe(true)
    expect(result.file.type).toBe('image/jpeg')
    expect(result.file.name).toBe('IMG_0042.jpg')
  })

  it('refuses a HEIC this browser cannot decode, saying why', async () => {
    await expect(preparePhoto(file('IMG_0042.HEIC', 'image/heic'))).rejects.toBeInstanceOf(
      UnsupportedPhotoError,
    )
  })

  it('refuses a file that is not a photo', async () => {
    await expect(preparePhoto(file('log.pdf', 'application/pdf'))).rejects.toThrow(/not a photo/i)
  })
})
