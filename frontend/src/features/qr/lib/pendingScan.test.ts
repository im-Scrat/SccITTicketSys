import { beforeEach, describe, expect, it } from 'vitest'
import {
  clearPendingScan,
  consumePendingScanPath,
  readPendingScan,
  rememberPendingScan,
  resumePath,
} from './pendingScan'

/**
 * Destination preservation across sign-in (SRS FR-QR-011; SDD DD-48).
 *
 * The requirement forbids a caller-supplied URL outright: the system carries the
 * **code** and rebuilds the destination itself, so that no query string,
 * referrer or stored value can send a signing-in user off this origin.
 *
 * These are the client's half of that rule, and unlike most frontend tests they
 * are not merely UX assertions — an open redirect is a real vulnerability and it
 * lives entirely in the browser. The store is asserted against the values an
 * attacker would actually try to plant in it, not just against a happy path.
 */
beforeEach(() => {
  sessionStorage.clear()
})

describe('the pending scan store', () => {
  it('keeps a scanned code and hands it back', () => {
    rememberPendingScan('PC-LAB4-01')

    expect(readPendingScan()).toBe('PC-LAB4-01')
  })

  it('clears on demand', () => {
    rememberPendingScan('PC-LAB4-01')
    clearPendingScan()

    expect(readPendingScan()).toBeNull()
  })

  it('consumes the stored code exactly once', () => {
    // A resumed scan must not be replayable by a later sign-in on the same tab.
    rememberPendingScan('PC-LAB4-01')

    expect(consumePendingScanPath()).toBe('/app/qr/PC-LAB4-01')
    expect(consumePendingScanPath()).toBeNull()
  })

  it('returns nothing when no scan is pending', () => {
    expect(consumePendingScanPath()).toBeNull()
  })
})

describe('what it refuses to store', () => {
  // Everything an attacker would put in front of a redirect. None of these is a
  // code, so none of them is kept — and the sign-in page falls back to /app.
  it.each([
    ['an absolute URL', 'https://evil.example/steal'],
    ['a protocol-relative path', '//evil.example'],
    ['a javascript: URL', 'javascript:alert(1)'],
    ['a data: URL', 'data:text/html,<script>alert(1)</script>'],
    ['a traversal path', '../../admin'],
    ['an app path', '/app/users'],
    ['an empty string', ''],
    ['a code with a slash', 'PC-LAB4/01'],
    ['an over-long value', 'A'.repeat(65)],
  ])('discards %s', (_label, value) => {
    rememberPendingScan(value)

    expect(readPendingScan()).toBeNull()
    expect(consumePendingScanPath()).toBeNull()
  })

  it('discards a poisoned value written directly into storage', () => {
    /*
     * The store is not the only way into sessionStorage — anything running on
     * this origin can write the key. So the shape check runs on *read* as well
     * as on write, and a planted URL is discarded rather than resumed.
     */
    sessionStorage.setItem('sccit.pendingScan', 'https://evil.example')

    expect(readPendingScan()).toBeNull()
    expect(consumePendingScanPath()).toBeNull()
  })
})

describe('the destination it builds', () => {
  it('always stays on this origin, whatever it is handed', () => {
    // resumePath composes into a fixed relative template, so even a value that
    // bypassed the store entirely cannot become an absolute URL.
    expect(resumePath('PC-LAB4-01')).toBe('/app/qr/PC-LAB4-01')
    expect(resumePath('//evil.example')).toBe('/app/qr/%2F%2Fevil.example')
    expect(resumePath('https://evil.example')).toBe('/app/qr/https%3A%2F%2Fevil.example')

    expect(resumePath('//evil.example').startsWith('/app/qr/')).toBe(true)
  })
})
