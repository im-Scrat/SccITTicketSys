import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createQrDecoder,
  DecoderUnavailableError,
  type DecoderEnvironment,
  type FrameSource,
  NATIVE_FAILURE_LIMIT,
} from './qrDecoder'

const LABEL = 'https://sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK'

/** A frame with real dimensions, so the fallback reads it. */
const frame = { videoWidth: 1280, videoHeight: 720 } as FrameSource

type Fallback = NonNullable<DecoderEnvironment['loadFallback']>

function nativeDetector(detect: () => Promise<Array<{ rawValue: string }>>, formats = ['qr_code']) {
  return class {
    static getSupportedFormats = vi.fn(async () => formats)
    detect = vi.fn(detect)
  } as unknown as DecoderEnvironment['BarcodeDetector']
}

class NotFoundException extends Error {}
class ChecksumException extends Error {}
class FormatException extends Error {}

/** A stand-in for @zxing/library: just the classes the fallback touches. */
function fakeZxing(decode: () => { getText(): string }): Fallback {
  return vi.fn(async () => {
    class QRCodeReader {
      decode = vi.fn(decode)
      reset = vi.fn()
    }
    class Noop {}
    return {
      QRCodeReader,
      HTMLCanvasElementLuminanceSource: Noop,
      BinaryBitmap: Noop,
      HybridBinarizer: Noop,
      NotFoundException,
      ChecksumException,
      FormatException,
      DecodeHintType: { TRY_HARDER: 3 },
    } as unknown as Awaited<ReturnType<Fallback>>
  })
}

function withCanvas() {
  return vi
    .spyOn(HTMLCanvasElement.prototype, 'getContext')
    .mockReturnValue({ drawImage: vi.fn() } as unknown as CanvasRenderingContext2D)
}

afterEach(() => vi.restoreAllMocks())

describe('createQrDecoder — choosing a decoder', () => {
  it('uses the browser’s BarcodeDetector when it reads QR codes, and never loads the fallback', async () => {
    const loadFallback = fakeZxing(() => ({ getText: () => 'unused' }))
    const decoder = await createQrDecoder({
      BarcodeDetector: nativeDetector(async () => [{ rawValue: LABEL }]),
      loadFallback,
    })

    expect(decoder.kind).toBe('native')
    expect(await decoder.decode(frame)).toBe(LABEL)
    expect(loadFallback).not.toHaveBeenCalled()
  })

  it('returns null from the native detector while no code is in view', async () => {
    const decoder = await createQrDecoder({
      BarcodeDetector: nativeDetector(async () => []),
      loadFallback: fakeZxing(() => ({ getText: () => 'unused' })),
    })

    expect(await decoder.decode(frame)).toBeNull()
  })

  it('falls back when there is no BarcodeDetector', async () => {
    withCanvas()
    const decoder = await createQrDecoder({
      BarcodeDetector: undefined,
      loadFallback: fakeZxing(() => ({ getText: () => LABEL })),
    })

    expect(decoder.kind).toBe('fallback')
    expect(await decoder.decode(frame)).toBe(LABEL)
  })

  it('falls back when the BarcodeDetector does not support QR codes', async () => {
    const decoder = await createQrDecoder({
      BarcodeDetector: nativeDetector(async () => [], ['ean_13']),
      loadFallback: fakeZxing(() => ({ getText: () => LABEL })),
    })

    expect(decoder.kind).toBe('fallback')
  })

  it('falls back when the BarcodeDetector fails to initialise', async () => {
    const Broken = class {
      static getSupportedFormats = vi.fn(async () => {
        throw new Error('not allowed')
      })
    } as unknown as DecoderEnvironment['BarcodeDetector']

    const decoder = await createQrDecoder({
      BarcodeDetector: Broken,
      loadFallback: fakeZxing(() => ({ getText: () => LABEL })),
    })

    expect(decoder.kind).toBe('fallback')
  })

  it('loads the real @zxing/library lazily when it is needed', async () => {
    const decoder = await createQrDecoder({ BarcodeDetector: undefined })

    expect(decoder.kind).toBe('fallback')
  })
})

describe('createQrDecoder — failures', () => {
  it('hands over to the fallback after the native detector fails repeatedly', async () => {
    withCanvas()
    const loadFallback = fakeZxing(() => ({ getText: () => LABEL }))
    const decoder = await createQrDecoder({
      BarcodeDetector: nativeDetector(async () => {
        throw new Error('detect failed')
      }),
      loadFallback,
    })

    for (let attempt = 1; attempt < NATIVE_FAILURE_LIMIT; attempt += 1) {
      expect(await decoder.decode(frame)).toBeNull()
    }
    expect(loadFallback).not.toHaveBeenCalled()

    expect(await decoder.decode(frame)).toBe(LABEL)
    expect(decoder.kind).toBe('fallback')
  })

  it('reports the decoder as unavailable when the fallback cannot be loaded', async () => {
    await expect(
      createQrDecoder({
        BarcodeDetector: undefined,
        loadFallback: vi.fn(async () => {
          throw new TypeError('Failed to fetch dynamically imported module')
        }),
      }),
    ).rejects.toBeInstanceOf(DecoderUnavailableError)
  })

  it('treats "no code in this frame" from the fallback as null, not an error', async () => {
    withCanvas()
    const decoder = await createQrDecoder({
      BarcodeDetector: undefined,
      loadFallback: fakeZxing(() => {
        throw new NotFoundException()
      }),
    })

    expect(await decoder.decode(frame)).toBeNull()
  })

  it('skips a frame that has no picture yet', async () => {
    const decoder = await createQrDecoder({
      BarcodeDetector: undefined,
      loadFallback: fakeZxing(() => ({ getText: () => LABEL })),
    })

    expect(await decoder.decode({ videoWidth: 0, videoHeight: 0 } as FrameSource)).toBeNull()
  })
})
