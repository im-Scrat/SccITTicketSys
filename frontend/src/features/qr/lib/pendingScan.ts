/**
 * Carrying the scanned destination across sign-in (SRS FR-QR-011; SDD DD-48).
 *
 * The requirement is unusually specific, and the reason is an old and reliable
 * vulnerability class:
 *
 * > "Preserve the scanned destination across authentication **without accepting
 * >  a caller-supplied URL**. The system shall carry only the scanned `code` and
 * >  rebuild the destination itself; it shall never redirect to an absolute URL,
 * >  a protocol-relative path, or any location outside the application origin,
 * >  whatever a query string, referrer or session value contains."
 *
 * So this module stores **a code, never a path**. {@see resumePath()} is the
 * only thing that builds a destination, and it builds it from a template. There
 * is no branch anywhere in this flow where a stored string is used as a URL, so
 * there is nothing for `//evil.example`, `https://evil.example` or `javascript:`
 * to be smuggled through — a poisoned value fails the shape check below and is
 * discarded, and the worst case is that the technician lands on the dashboard.
 *
 * `sessionStorage`, not `localStorage`: a scan is a moment, not a preference.
 * It should not outlive the tab, and it must not follow the next person to use
 * a shared workshop machine.
 */

const KEY = 'sccit.pendingScan'

/**
 * The same shape the route accepts and the API's `where()` constraint enforces.
 * Anything else was not written by this application.
 */
const CODE_PATTERN = /^[A-Za-z0-9-]{1,64}$/

function isCode(value: unknown): value is string {
  return typeof value === 'string' && CODE_PATTERN.test(value)
}

/** Remember the code the visitor scanned before they were sent to sign in. */
export function rememberPendingScan(code: string): void {
  if (!isCode(code)) return

  try {
    sessionStorage.setItem(KEY, code)
  } catch {
    // Private browsing, a full quota, or storage disabled by policy. Losing the
    // destination costs one navigation; it is never worth failing sign-in over.
  }
}

/** The code to resume, if one was stored and still looks like a code. */
export function readPendingScan(): string | null {
  try {
    const stored = sessionStorage.getItem(KEY)

    return isCode(stored) ? stored : null
  } catch {
    return null
  }
}

export function clearPendingScan(): void {
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    // Nothing to do, and nothing depends on it having worked.
  }
}

/**
 * Build the in-app destination for a scanned code.
 *
 * The only function that turns a code into a location, and it does so by
 * composition — the code is URL-encoded into a fixed, relative template. It
 * cannot produce an absolute URL, a protocol-relative path, or anything off
 * this origin, whatever it is handed.
 */
export function resumePath(code: string): string {
  return `/app/qr/${encodeURIComponent(code)}`
}

/**
 * Take the stored code, if any, and turn it into a destination — clearing it in
 * the same step so a resumed scan cannot be replayed by a later sign-in.
 */
export function consumePendingScanPath(): string | null {
  const code = readPendingScan()

  if (code === null) return null

  clearPendingScan()

  return resumePath(code)
}
