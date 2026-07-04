import type { ReactNode } from 'react'
import { ScanLine } from 'lucide-react'
import { cn } from '@/lib/cn'
import { StatusPill } from '@/components/ui/StatusPill'

/**
 * The panel a technician sees after scanning an asset QR (FR-QR-005): the code
 * resolves to a live target and returns its details + scan verification. The QR
 * glyph is a deterministic, decorative matrix (not a real payload) so it renders
 * identically every time without external assets.
 */

const MATRIX_SIZE = 25

// Small deterministic PRNG (mulberry32) — a fixed seed gives a stable pattern.
function mulberry32(seed: number) {
  return function () {
    seed |= 0
    seed = (seed + 0x6d2b79f5) | 0
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

function isFinderZone(r: number, c: number): boolean {
  const inTopLeft = r < 8 && c < 8
  const inTopRight = r < 8 && c >= MATRIX_SIZE - 8
  const inBottomLeft = r >= MATRIX_SIZE - 8 && c < 8
  return inTopLeft || inTopRight || inBottomLeft
}

function finderModule(r: number, c: number): boolean {
  // Position relative to the nearest 7x7 finder origin.
  const rr = r < 8 ? r : r - (MATRIX_SIZE - 7)
  const cc = c < 8 ? c : c - (MATRIX_SIZE - 7)
  if (rr < 0 || rr > 6 || cc < 0 || cc > 6) return false
  const ring = rr === 0 || rr === 6 || cc === 0 || cc === 6
  const core = rr >= 2 && rr <= 4 && cc >= 2 && cc <= 4
  return ring || core
}

const matrix: boolean[][] = (() => {
  const rand = mulberry32(0x5cc17)
  const grid: boolean[][] = []
  for (let r = 0; r < MATRIX_SIZE; r++) {
    const row: boolean[] = []
    for (let c = 0; c < MATRIX_SIZE; c++) {
      if (r < 8 && c < 8) row.push(finderModule(r, c))
      else if (r < 8 && c >= MATRIX_SIZE - 8) row.push(finderModule(r, c))
      else if (r >= MATRIX_SIZE - 8 && c < 8) row.push(finderModule(r, c))
      else row.push(!isFinderZone(r, c) && rand() > 0.52)
    }
    grid.push(row)
  }
  return grid
})()

function QrGlyph({ className }: { className?: string }) {
  return (
    <svg
      viewBox={`0 0 ${MATRIX_SIZE} ${MATRIX_SIZE}`}
      // Fixed dark modules — the tile is always white in both themes, so the QR
      // must not follow the (theme-flipping) ink token or it vanishes in dark.
      className={cn('text-[#0f172a]', className)}
      aria-hidden="true"
      shapeRendering="crispEdges"
    >
      {matrix.map((row, r) =>
        row.map((on, c) =>
          on ? (
            <rect key={`${r}-${c}`} x={c} y={r} width={1} height={1} fill="currentColor" />
          ) : null,
        ),
      )}
    </svg>
  )
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 py-1.5">
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="text-right text-[13px] text-ink">{children}</dd>
    </div>
  )
}

export function QrAssetVisual({ className }: { className?: string }) {
  return (
    <figure
      role="img"
      aria-label="An asset detail panel returned by scanning a QR code: workstation LAB2-PC-014, online, with model, serial, location, and a verified scan record."
      className={cn('overflow-hidden rounded-lg border border-border bg-surface', className)}
    >
      <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
        <span className="text-sm font-semibold text-ink-strong">Asset</span>
        <span className="slashed-zero font-mono text-[11px] text-muted">LAB2-PC-014</span>
      </div>

      <div className="grid gap-4 p-4 sm:grid-cols-[auto_1fr]">
        <div className="flex items-center justify-center rounded-md border border-border bg-white p-2.5">
          <QrGlyph className="size-24" />
        </div>

        <dl className="min-w-0 divide-y divide-border">
          <Field label="Model">Dell OptiPlex 7010</Field>
          <Field label="Serial">
            <span className="slashed-zero font-mono text-[12px]">7QF2K93</span>
          </Field>
          <Field label="Location">Lab 2 · Ground floor</Field>
          <Field label="Status">
            <StatusPill status="online" />
          </Field>
        </dl>
      </div>

      <div className="flex items-center gap-2 border-t border-border bg-surface-sunken px-4 py-2.5 text-[12px] text-success-strong">
        <ScanLine size={15} aria-hidden="true" />
        <span className="font-medium">Scan verified</span>
        <span className="text-muted">
          2m ago · by J. Rivera ·{' '}
          <span className="slashed-zero font-mono text-[11px]">10.20.4.12</span>
        </span>
      </div>
    </figure>
  )
}
