/**
 * Reads a QR code from the live camera preview (owner brief 2026-10-20, SDD
 * DD-117).
 *
 * Two decoders, in order of preference:
 *
 *  1. **The browser's own `BarcodeDetector`**, when it exists and lists
 *     `qr_code` among its formats (Chrome on Android, and others). Fast, and
 *     nothing to download.
 *  2. **`@zxing/library`**, pinned at 0.23.0, loaded with a dynamic `import()`
 *     only when the first is missing or keeps failing — so it is its own
 *     chunk, never part of the initial bundle, and most phones never fetch it.
 *     It is plain JavaScript: no WebAssembly, no workers, no `eval`, so the
 *     strict Content-Security-Policy stays as it is.
 *
 * A native detector that throws on several frames in a row (some
 * implementations fail on particular camera formats) hands over to the
 * fallback rather than leaving the technician pointing at a label forever.
 * When neither can be had, {@see DecoderUnavailableError} says so and the
 * dialog shows it.
 *
 * Decoding only ever returns the label's text; turning text into a code is
 * {@see codeFromScannedPayload}'s job, and only a code ever leads anywhere.
 */

export class DecoderUnavailableError extends Error {
  constructor(cause?: unknown) {
    super('No QR decoder is available in this browser.')
    this.name = 'DecoderUnavailableError'
    this.cause = cause
  }
}

/** The frame source the decoders read: the preview `<video>`. */
export type FrameSource = HTMLVideoElement

export interface QrDecoder {
  readonly kind: 'native' | 'fallback'
  /** The text of a QR code in this frame, or null when none is found yet. */
  decode(frame: FrameSource): Promise<string | null>
}

interface DetectedBarcode {
  rawValue: string
}

interface NativeDetector {
  detect(source: FrameSource): Promise<DetectedBarcode[]>
}

interface NativeDetectorConstructor {
  new (options: { formats: string[] }): NativeDetector
  getSupportedFormats(): Promise<string[]>
}

type ZxingModule = typeof import('@zxing/library')

export interface DecoderEnvironment {
  /** `globalThis.BarcodeDetector`, or undefined where the browser has none. */
  BarcodeDetector?: NativeDetectorConstructor
  /** The fallback's dynamic import — injectable so a failed fetch can be tested. */
  loadFallback?: () => Promise<ZxingModule>
}

/** Consecutive native failures before handing over to the fallback. */
export const NATIVE_FAILURE_LIMIT = 3

/** Frames are scaled down to this width before the fallback reads them. */
const FALLBACK_MAX_WIDTH = 640

export async function createQrDecoder(
  env: DecoderEnvironment = defaultEnvironment(),
): Promise<QrDecoder> {
  const loadFallback = env.loadFallback ?? (() => import('@zxing/library'))
  const native = await createNative(env.BarcodeDetector)

  if (native === null) {
    return createFallback(loadFallback)
  }

  let failures = 0
  let fallback: QrDecoder | null = null

  return {
    get kind() {
      return fallback ? fallback.kind : native.kind
    },
    async decode(frame) {
      if (fallback) return fallback.decode(frame)

      try {
        const text = await native.decode(frame)
        failures = 0
        return text
      } catch (error) {
        failures += 1
        if (failures < NATIVE_FAILURE_LIMIT) return null
        // Hand over for good; a fallback that cannot load is reported as such.
        fallback = await createFallback(loadFallback, error)
        return fallback.decode(frame)
      }
    },
  }
}

function defaultEnvironment(): DecoderEnvironment {
  const candidate = (globalThis as { BarcodeDetector?: NativeDetectorConstructor }).BarcodeDetector

  return { BarcodeDetector: candidate }
}

async function createNative(
  Detector: NativeDetectorConstructor | undefined,
): Promise<QrDecoder | null> {
  if (typeof Detector !== 'function') return null

  try {
    const formats = await Detector.getSupportedFormats()
    if (!formats.includes('qr_code')) return null

    const detector = new Detector({ formats: ['qr_code'] })

    return {
      kind: 'native',
      async decode(frame) {
        const found = await detector.detect(frame)
        const text = found.find((code) => typeof code.rawValue === 'string')?.rawValue

        return text ?? null
      },
    }
  } catch {
    // An initialisation failure is not the technician's problem: use the fallback.
    return null
  }
}

async function createFallback(
  load: () => Promise<ZxingModule>,
  cause?: unknown,
): Promise<QrDecoder> {
  let zxing: ZxingModule
  try {
    zxing = await load()
  } catch (error) {
    throw new DecoderUnavailableError(error ?? cause)
  }

  let reader: InstanceType<ZxingModule['QRCodeReader']>
  try {
    reader = new zxing.QRCodeReader()
  } catch (error) {
    throw new DecoderUnavailableError(error)
  }

  const hints = new Map([[zxing.DecodeHintType.TRY_HARDER, true]])
  const canvas = document.createElement('canvas')

  return {
    kind: 'fallback',
    async decode(frame) {
      const width = frame.videoWidth
      const height = frame.videoHeight
      if (!width || !height) return null

      const scale = Math.min(1, FALLBACK_MAX_WIDTH / width)
      canvas.width = Math.round(width * scale)
      canvas.height = Math.round(height * scale)

      const context = canvas.getContext('2d', { willReadFrequently: true })
      if (!context) throw new DecoderUnavailableError()
      context.drawImage(frame, 0, 0, canvas.width, canvas.height)

      try {
        const luminance = new zxing.HTMLCanvasElementLuminanceSource(canvas)
        const bitmap = new zxing.BinaryBitmap(new zxing.HybridBinarizer(luminance))

        return reader.decode(bitmap, hints).getText()
      } catch (error) {
        // "Nothing readable in this frame" is the normal case between frames.
        if (
          error instanceof zxing.NotFoundException ||
          error instanceof zxing.ChecksumException ||
          error instanceof zxing.FormatException
        ) {
          return null
        }
        throw error
      } finally {
        reader.reset()
      }
    },
  }
}
