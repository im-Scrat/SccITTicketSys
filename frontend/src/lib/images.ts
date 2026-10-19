/**
 * Preparing a photo in the browser before it is uploaded (SDD DD-80).
 *
 * One pipeline, shared by every surface that takes a photograph for a ticket —
 * the report form and the ticket's "Photos & files" tab — so a photo that one
 * surface accepts the other cannot refuse for a different reason.
 *
 * ── Why the client touches the file at all ─────────────────────────────────
 *
 *  - **The server judges the filename, not only the bytes.** The upload rule's
 *    `extensions:` check reads the client's filename, so a perfectly good JPEG
 *    called `IMG_0042.heic`, or an Android photo with no extension at all, is
 *    refused — and so is a Windows `.jfif`, which is a JPEG by any other name.
 *    Every prepared file is therefore renamed to an extension the server
 *    accepts for what it really is — always, not only when it was re-encoded.
 *  - **Phone photos are large.** A modern camera produces 4000 px, 3–8 MB files
 *    that take minutes on school Wi-Fi and can exceed the per-file limit. Only a
 *    photo over {@link MAX_LONG_EDGE} px on its long edge, or over
 *    {@link REENCODE_OVER_BYTES}, is redrawn as a JPEG at that size. Anything
 *    smaller goes up byte-for-byte, and a GIF is never redrawn (it may be
 *    animated, and redrawing would keep only the first frame). The exception
 *    is a HEIC, or a file whose type cannot be told: when this browser can
 *    decode it, it is always redrawn, because its original is not a type the
 *    server accepts.
 *
 * ── What this does NOT promise ─────────────────────────────────────────────
 *
 * A redrawn photo carries no camera metadata, because a canvas has none to
 * copy. That is a side effect, not a feature: a photo uploaded unchanged keeps
 * whatever metadata (location included) the camera wrote, and so does any photo
 * that falls back to its original after a failure here. Nothing in the product
 * may describe this pipeline as removing location data.
 *
 * ── Orientation ────────────────────────────────────────────────────────────
 *
 * Phone cameras store a portrait photo as a landscape image plus an orientation
 * tag. Drawing it onto a canvas loses the tag, so the decode must apply it
 * first: `createImageBitmap(file, { imageOrientation: 'from-image' })` does,
 * and an engine that does not know that option throws, which falls through to
 * an `<img>` decode — browsers honour the tag there by default
 * (`image-orientation: from-image`). The fallback is unit-tested; whether a
 * photo actually arrives upright cannot be tested in jsdom, and the release
 * checklist covers it on real phones.
 */

export const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'] as const
export type PhotoType = (typeof PHOTO_TYPES)[number]

/**
 * The file-picker filter. HEIC is deliberately absent: an iPhone offered only
 * these types converts its HEIC photo to JPEG before handing it over, which is
 * the conversion this app cannot do itself.
 */
export const PHOTO_ACCEPT = PHOTO_TYPES.join(',')

/** Long edge, in px, above which a photo is redrawn smaller. */
export const MAX_LONG_EDGE = 2560

/** Size above which a photo is redrawn even when its dimensions are modest. */
export const REENCODE_OVER_BYTES = 4 * 1024 * 1024

export const JPEG_QUALITY = 0.85

const EXTENSION_OF: Record<PhotoType, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/gif': 'gif',
}

/**
 * What an extension says a file is — for *classifying* a picked file only.
 * `jfif` is here because Chrome and Edge on Windows often save a web JPEG under
 * it; it is not an extension the server accepts (see {@link KEEP_EXTENSIONS}).
 */
const TYPE_OF_EXTENSION: Record<string, PhotoType> = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  jfif: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  gif: 'image/gif',
}

/**
 * The extensions the server's upload rule accepts for each type
 * (`AttachmentSecurity`'s ticket profile, checked against the client's
 * filename). A name is kept only when its extension is in here — knowing what
 * an extension *means* is not the same as the server accepting it, which is
 * why `photo.jfif` must still become `photo.jpg`.
 */
const KEEP_EXTENSIONS: Record<PhotoType, readonly string[]> = {
  'image/jpeg': ['jpg', 'jpeg'],
  'image/png': ['png'],
  'image/webp': ['webp'],
  'image/gif': ['gif'],
}

const HEIC_TYPES = new Set([
  'image/heic',
  'image/heif',
  'image/heic-sequence',
  'image/heif-sequence',
])
const HEIC_EXTENSIONS = new Set(['heic', 'heif'])

/** What a picked file appears to be, before anything has been decoded. */
export type PhotoKind = PhotoType | 'heic' | 'unknown' | 'not-image'

/** A photo this browser cannot turn into something the server accepts. */
export class UnsupportedPhotoError extends Error {
  constructor(
    message = 'This photo’s format cannot be used. Take it again, or choose a JPEG or PNG.',
  ) {
    super(message)
    this.name = 'UnsupportedPhotoError'
  }
}

export interface PreparedPhoto {
  /** The file to upload: always named with an extension the server accepts for its real type. */
  file: File
  /** True when the photo was redrawn (smaller, as JPEG); false when its bytes are untouched. */
  reencoded: boolean
}

function extensionOf(name: string): string | null {
  const match = /\.([A-Za-z0-9]{1,5})$/.exec(name.trim())
  return match ? match[1].toLowerCase() : null
}

/**
 * Classify a picked file: by its MIME type first, then by its extension.
 *
 * A file the browser could not type (`''`, or the generic
 * `application/octet-stream` some Android pickers report) is `unknown` rather
 * than refused — {@link preparePhoto} tries to decode it before giving up. A
 * file that declares a non-image type (a PDF, a text log) is `not-image`.
 */
export function photoKind(file: Pick<File, 'name' | 'type'>): PhotoKind {
  const type = (file.type ?? '').toLowerCase()
  if ((PHOTO_TYPES as readonly string[]).includes(type)) return type as PhotoType
  if (HEIC_TYPES.has(type)) return 'heic'

  const extension = extensionOf(file.name ?? '')
  if (extension && TYPE_OF_EXTENSION[extension]) return TYPE_OF_EXTENSION[extension]
  if (extension && HEIC_EXTENSIONS.has(extension)) return 'heic'

  if (type === '' || type === 'application/octet-stream' || type.startsWith('image/')) {
    return 'unknown'
  }
  return 'not-image'
}

/**
 * `name` with the extension of `type`: `IMG_0042.heic` → `IMG_0042.jpg`,
 * `photo` → `photo.jpg`, `photo.jfif` → `photo.jpg`. A name that already
 * carries an extension the server accepts for that type is kept as it is, so
 * `holiday.jpeg` stays `.jpeg`.
 */
export function withExtension(name: string, type: PhotoType): string {
  const trimmed = name.trim()
  const extension = extensionOf(trimmed)

  if (extension && KEEP_EXTENSIONS[type].includes(extension)) return trimmed

  const base = extension ? trimmed.slice(0, -(extension.length + 1)) : trimmed
  return `${base || 'photo'}.${EXTENSION_OF[type]}`
}

/** The same bytes under a corrected name and type; the same object when nothing changes. */
function renamed(file: File, type: PhotoType): File {
  const name = withExtension(file.name, type)
  if (name === file.name && file.type === type) return file
  return new File([file], name, { type, lastModified: file.lastModified })
}

interface Decoded {
  source: CanvasImageSource
  width: number
  height: number
  release: () => void
}

/**
 * Decode with the orientation tag applied. `null` when the browser cannot
 * decode the file at all (an Android HEIC, a corrupt file).
 */
async function decode(file: File): Promise<Decoded | null> {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
      return {
        source: bitmap,
        width: bitmap.width,
        height: bitmap.height,
        release: () => bitmap.close(),
      }
    } catch {
      // Either the option is unknown to this engine (it throws on the enum
      // value) or the bytes are undecodable; the <img> path settles which.
    }
  }

  if (typeof Image === 'undefined' || typeof URL.createObjectURL !== 'function') return null

  const url = URL.createObjectURL(file)
  try {
    const image = new Image()
    image.decoding = 'async'
    image.src = url
    await image.decode()
    return {
      source: image,
      width: image.naturalWidth,
      height: image.naturalHeight,
      release: () => {
        URL.revokeObjectURL(url)
        image.src = ''
      },
    }
  } catch {
    URL.revokeObjectURL(url)
    return null
  }
}

/**
 * One canvas for every photo. Phones cap the total canvas memory a page may
 * hold and release it lazily, so a fresh canvas per photo can exhaust it after
 * a handful of 12-megapixel images; reusing one and shrinking it to nothing
 * after each encode keeps the footprint to a single photo.
 */
let sharedCanvas: HTMLCanvasElement | null = null

function canvas(): HTMLCanvasElement {
  sharedCanvas ??= document.createElement('canvas')
  return sharedCanvas
}

/** Redraw at most {@link MAX_LONG_EDGE} px as a JPEG. `null` when the canvas refuses. */
async function encodeJpeg(decoded: Decoded, name: string, lastModified: number) {
  const scale = Math.min(1, MAX_LONG_EDGE / Math.max(decoded.width, decoded.height))
  const width = Math.max(1, Math.round(decoded.width * scale))
  const height = Math.max(1, Math.round(decoded.height * scale))

  const target = canvas()
  target.width = width
  target.height = height

  try {
    const context = target.getContext('2d')
    if (!context) return null

    // JPEG has no transparency: without a fill, a transparent PNG's background
    // would come out black.
    context.fillStyle = '#ffffff'
    context.fillRect(0, 0, width, height)
    context.drawImage(decoded.source, 0, 0, width, height)

    const blob = await new Promise<Blob | null>((resolve) =>
      target.toBlob(resolve, 'image/jpeg', JPEG_QUALITY),
    )
    if (!blob) return null

    return new File([blob], withExtension(name, 'image/jpeg'), {
      type: 'image/jpeg',
      lastModified,
    })
  } finally {
    target.width = 0
    target.height = 0
  }
}

/**
 * Make a picked photo ready to upload.
 *
 *  - JPEG, PNG, WebP: redrawn as a JPEG when over {@link MAX_LONG_EDGE} px or
 *    {@link REENCODE_OVER_BYTES}; otherwise the original bytes. Any failure
 *    along the way returns the original — a photo that could not be shrunk is
 *    still worth trying to send.
 *  - GIF: never redrawn.
 *  - HEIC, or a file whose type could not be told: decoded if this browser can
 *    (and then always redrawn, because the original is not a type the server
 *    accepts); otherwise {@link UnsupportedPhotoError}.
 *  - A declared non-image (PDF, text): {@link UnsupportedPhotoError}.
 *
 * Whatever comes back is named with the extension of its real type.
 */
export async function preparePhoto(file: File): Promise<PreparedPhoto> {
  const kind = photoKind(file)

  if (kind === 'not-image') {
    throw new UnsupportedPhotoError(
      'That file is not a photo. Choose a JPEG, PNG, WebP or GIF image.',
    )
  }

  if (kind === 'image/gif') return { file: renamed(file, kind), reencoded: false }

  const decoded = await decode(file)

  if (kind === 'heic' || kind === 'unknown') {
    if (!decoded) throw new UnsupportedPhotoError()
    const encoded = await encodeSafely(decoded, file)
    if (!encoded) throw new UnsupportedPhotoError()
    return { file: encoded, reencoded: true }
  }

  // JPEG, PNG or WebP: the original is acceptable as it stands.
  if (!decoded) return { file: renamed(file, kind), reencoded: false }

  const oversized =
    Math.max(decoded.width, decoded.height) > MAX_LONG_EDGE || file.size > REENCODE_OVER_BYTES
  if (!oversized) {
    decoded.release()
    return { file: renamed(file, kind), reencoded: false }
  }

  const encoded = await encodeSafely(decoded, file)
  return encoded
    ? { file: encoded, reencoded: true }
    : { file: renamed(file, kind), reencoded: false }
}

/** {@link encodeJpeg}, with any canvas failure reported as `null`; always releases the decode. */
async function encodeSafely(decoded: Decoded, file: File): Promise<File | null> {
  try {
    return await encodeJpeg(decoded, file.name, file.lastModified)
  } catch {
    return null
  } finally {
    decoded.release()
  }
}
