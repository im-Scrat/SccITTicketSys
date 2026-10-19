import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { QrPayload } from '../api/assetsApi'
import type { QrCodeItem } from '../types'
import { QrCodePanel } from './QrCodePanel'

const fetchQrCodes = vi.hoisted(() => vi.fn())

vi.mock('../api/assetsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api/assetsApi')>()),
  fetchQrCodes,
  generateQr: vi.fn(),
  regenerateQr: vi.fn(),
  revokeQr: vi.fn(),
  fetchQrPrint: vi.fn(),
}))

/**
 * QR label management for one asset or PC (SRS FR-QR-001..004/007).
 *
 * **The property this file exists to protect: a machine that has a label is
 * shown as having one.** The panel reads `meta.active` from the API, and the
 * shape of that field is a trap — Laravel wraps a resource in `data` only at the
 * top level of a response, and `meta.active` is nested, so it arrives
 * *unwrapped*. Reading `meta.active.data` yielded `undefined`, the panel took
 * that for "no label", and it rendered the empty state over a PC that had an
 * active code. Generation appeared to do nothing and **Print label was
 * unreachable**, which broke the physical provisioning workflow end to end.
 *
 * Neither the compiler nor the backend suite could catch it: the interface was
 * hand-written to match the wrong assumption, so TypeScript agreed with the bug,
 * and the API it describes was correct all along.
 *
 * Every fixture below is therefore **the literal response shape**, copied from a
 * real `GET /api/admin/pc-units/{uuid}/qr` — not a convenient reshaping of it. A
 * fixture that "fixes up" the payload would re-open exactly this hole.
 */
function qrCode(overrides: Partial<QrCodeItem> = {}): QrCodeItem {
  return {
    id: '556049aa-0eb0-4d22-ba0e-32048db306f1',
    code: 'PC-0LWODARGNO',
    payload: 'http://localhost:8080/qr/PC-0LWODARGNO',
    location_label: 'Main Building · Floor 2 · Laboratory 4',
    status: 'active',
    status_label: 'Active',
    is_active: true,
    generated_at: '2026-08-30T03:36:15+00:00',
    last_scanned_at: null,
    target_type: 'pc_unit',
    ...overrides,
  }
}

/** A 1x1 SVG data URI, standing in for the rendered label. */
const SVG = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4='

function payloadWithActive(): QrPayload {
  const active = qrCode()
  return {
    data: [active],
    // `active` sits beside `data`, unwrapped — this is what the API sends.
    meta: { active, svg: SVG, default_size: 256, error_correction: 'M' },
  }
}

function payloadWithNoLabel(): QrPayload {
  return {
    data: [],
    meta: { active: null, svg: null, default_size: 256, error_correction: 'M' },
  }
}

/** A revoked history entry with no active successor (FR-QR-007). */
function payloadRevokedOnly(): QrPayload {
  return {
    data: [qrCode({ status: 'revoked', status_label: 'Revoked', is_active: false })],
    meta: { active: null, svg: null, default_size: 256, error_correction: 'M' },
  }
}

function renderPanel(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>)
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('an active label is displayed', () => {
  beforeEach(() => {
    fetchQrCodes.mockResolvedValue(payloadWithActive())
  })

  it('renders the label instead of the empty state', async () => {
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    // The regression in one assertion: the empty state must not win when a
    // label exists.
    expect(await screen.findByText('PC-0LWODARGNO')).toBeInTheDocument()
    expect(screen.queryByText(/no qr label yet/i)).not.toBeInTheDocument()
  })

  it('renders the QR graphic from the payload svg', async () => {
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    const img = await screen.findByRole('img', { name: /qr code pc-0lwodargno/i })
    expect(img).toHaveAttribute('src', SVG)
  })

  it('offers Print label once a label exists', async () => {
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    // Printing is what the whole workflow exists for: the sticker goes on the
    // machine. It was unreachable while `active` was always null.
    expect(await screen.findByRole('button', { name: /print label/i })).toBeInTheDocument()
  })

  it('shows the printed location when the label carries one', async () => {
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    expect(await screen.findByText(/main building · floor 2 · laboratory 4/i)).toBeInTheDocument()
  })

  it('offers Regenerate and Revoke to a manager', async () => {
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    expect(await screen.findByRole('button', { name: /regenerate/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /revoke/i })).toBeInTheDocument()
  })

  it('withholds Regenerate and Revoke from a reader, but still allows printing', async () => {
    // `canManage` is the existing distinction between reading the register and
    // changing it; printing an existing label is not a change.
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage={false} />)

    expect(await screen.findByRole('button', { name: /print label/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /regenerate/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /revoke/i })).not.toBeInTheDocument()
  })
})

describe('no active label', () => {
  it('shows the empty state and offers generation to a manager', async () => {
    fetchQrCodes.mockResolvedValue(payloadWithNoLabel())
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    expect(await screen.findByText(/no qr label yet/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /generate qr code/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /print label/i })).not.toBeInTheDocument()
  })

  it('withholds generation from a reader', async () => {
    fetchQrCodes.mockResolvedValue(payloadWithNoLabel())
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage={false} />)

    expect(await screen.findByText(/no qr label yet/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /generate qr code/i })).not.toBeInTheDocument()
  })

  it('treats a revoked-only history as having no active label', async () => {
    // Revoking preserves history rather than deleting it (FR-QR-007), so rows
    // exist while `meta.active` is null. The panel must follow `active`, not the
    // presence of rows.
    fetchQrCodes.mockResolvedValue(payloadRevokedOnly())
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    expect(await screen.findByText(/no qr label yet/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /generate qr code/i })).toBeInTheDocument()
  })
})

describe('the payload shape itself', () => {
  it('reads meta.active unwrapped, as the API sends it', async () => {
    // Guards the exact defect: if the panel ever reaches one level deeper again,
    // this fixture — which carries no `data` wrapper on `meta.active` — makes it
    // fall back to the empty state and fail here.
    const payload = payloadWithActive()
    expect(payload.meta.active).not.toBeNull()
    expect(payload.meta.active).not.toHaveProperty('data')

    fetchQrCodes.mockResolvedValue(payload)
    renderPanel(<QrCodePanel kind="pc-units" id="pc-1" canManage />)

    expect(await screen.findByText('PC-0LWODARGNO')).toBeInTheDocument()
  })

  it('works the same for a standalone asset', async () => {
    // One component serves both targets, so the fix must hold for both.
    fetchQrCodes.mockResolvedValue({
      ...payloadWithActive(),
      meta: {
        ...payloadWithActive().meta,
        active: qrCode({ code: 'AS-4KD9WQ1ZTM', target_type: 'asset' }),
      },
    })
    renderPanel(<QrCodePanel kind="assets" id="asset-1" canManage />)

    expect(await screen.findByText('AS-4KD9WQ1ZTM')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /print label/i })).toBeInTheDocument()
  })
})
