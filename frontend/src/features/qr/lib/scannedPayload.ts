import { isCode } from './pendingScan'

/**
 * What the in-app scanner accepts from a label (owner brief 2026-10-20, SDD
 * DD-117).
 *
 * A printed PC label encodes `<origin>/qr/<code>` — the origin is whatever
 * FRONTEND_URL was when it was rendered (DD-113), so a label printed on one
 * deployment and scanned on another still carries a usable code. This module
 * therefore extracts **only the code** and never the URL: the scanner then
 * navigates with {@see resumePath()}, the one function that builds a QR
 * destination, so a QR code pointing at `https://evil.example/qr/X` can at
 * worst open this application's own panel for `X` — where the server decides,
 * as it always does, whether this technician may see it.
 *
 * Rejected: anything that is not an absolute http(s) URL, URLs carrying
 * credentials, paths that do not end in exactly `/qr/<code>` (an extra segment
 * after the code, a query-string or fragment pretending to be the path), codes
 * outside the shape the route and the API accept, and oversized payloads.
 */

/** Generously above any real label URL; a QR code can hold ~3 KB. */
export const MAX_PAYLOAD_LENGTH = 2048

/** `/qr/<code>` at the very end of the path, with an optional trailing slash. */
const QR_PATH = /\/qr\/([^/]+)\/?$/

export function codeFromScannedPayload(raw: unknown): string | null {
  if (typeof raw !== 'string') return null

  const text = raw.trim()
  if (text === '' || text.length > MAX_PAYLOAD_LENGTH) return null

  // `new URL` alone would resolve nothing here, but it accepts schemes such as
  // `javascript:` and `data:`; the explicit prefix check keeps it to http(s).
  if (!/^https?:\/\//i.test(text)) return null

  let url: URL
  try {
    url = new URL(text)
  } catch {
    return null
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') return null
  if (url.username !== '' || url.password !== '') return null

  const match = QR_PATH.exec(url.pathname)
  if (!match) return null

  let code: string
  try {
    code = decodeURIComponent(match[1])
  } catch {
    return null
  }

  return isCode(code) ? code : null
}
