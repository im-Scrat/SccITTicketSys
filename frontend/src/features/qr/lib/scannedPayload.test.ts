import { describe, expect, it } from 'vitest'
import { codeFromScannedPayload, MAX_PAYLOAD_LENGTH } from './scannedPayload'

describe('codeFromScannedPayload — what a scanned label may lead to', () => {
  it.each([
    ['https://sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK', 'PC-ABCDEFGHJK'],
    ['http://localhost:8081/qr/PC-ABCDEFGHJK', 'PC-ABCDEFGHJK'],
    ['HTTPS://SCCIT-ITSM.ONRENDER.COM/qr/PC-ABCDEFGHJK', 'PC-ABCDEFGHJK'],
    ['https://sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK/', 'PC-ABCDEFGHJK'],
    ['  https://sccit-itsm.onrender.com/qr/PC-E2EFIXTURE \n', 'PC-E2EFIXTURE'],
    // Demo databases hold uuid-shaped codes (QrCodeFactory).
    [
      'https://sccit-itsm.onrender.com/qr/2f1c7d6e-9a7b-4c1d-8e2f-0a1b2c3d4e5f',
      '2f1c7d6e-9a7b-4c1d-8e2f-0a1b2c3d4e5f',
    ],
    // A label rendered on a deployment served under a sub-path still ends in /qr/<code>.
    ['https://school.example/it/qr/PC-ABCDEFGHJK', 'PC-ABCDEFGHJK'],
    // A query or fragment never decides the code; the path does.
    ['https://sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK?utm=print#x', 'PC-ABCDEFGHJK'],
  ])('accepts %s', (payload, code) => {
    expect(codeFromScannedPayload(payload)).toBe(code)
  })

  it.each([
    ['a bare code', 'PC-ABCDEFGHJK'],
    ['a relative path', '/qr/PC-ABCDEFGHJK'],
    ['a protocol-relative URL', '//evil.example/qr/PC-ABCDEFGHJK'],
    ['javascript:', 'javascript:alert(1)//qr/PC-ABCDEFGHJK'],
    ['data:', 'data:text/html,/qr/PC-ABCDEFGHJK'],
    ['ftp:', 'ftp://example.org/qr/PC-ABCDEFGHJK'],
    ['credentials in the URL', 'https://user:pass@sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK'],
    ['a segment after the code', 'https://sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK/extra'],
    ['no code', 'https://sccit-itsm.onrender.com/qr/'],
    ['a different route', 'https://sccit-itsm.onrender.com/app/qr-codes/PC-ABCDEFGHJK'],
    ['the path only in the query', 'https://evil.example/?next=/qr/PC-ABCDEFGHJK'],
    ['the path only in the fragment', 'https://evil.example/#/qr/PC-ABCDEFGHJK'],
    ['a code with forbidden characters', 'https://sccit-itsm.onrender.com/qr/PC_ABC.DEF'],
    ['an encoded slash in the code', 'https://sccit-itsm.onrender.com/qr/PC%2F..%2Fadmin'],
    ['a malformed escape', 'https://sccit-itsm.onrender.com/qr/PC-%E0%A4%A'],
    ['an overlong code', `https://sccit-itsm.onrender.com/qr/${'A'.repeat(65)}`],
    ['a malformed URL', 'https://'],
    ['plain text', 'hello world'],
    ['an empty string', ''],
  ])('refuses %s', (_case, payload) => {
    expect(codeFromScannedPayload(payload)).toBeNull()
  })

  it('refuses an oversized payload before parsing it', () => {
    const padded = `https://sccit-itsm.onrender.com/qr/PC-ABCDEFGHJK?${'x'.repeat(MAX_PAYLOAD_LENGTH)}`

    expect(codeFromScannedPayload(padded)).toBeNull()
  })

  it('refuses anything that is not a string', () => {
    expect(codeFromScannedPayload(undefined)).toBeNull()
    expect(codeFromScannedPayload(42)).toBeNull()
    expect(codeFromScannedPayload({ rawValue: 'https://x/qr/PC-1' })).toBeNull()
  })
})
