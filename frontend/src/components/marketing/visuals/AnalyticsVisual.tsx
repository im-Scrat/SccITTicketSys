import type { ReactNode } from 'react'
import { TrendingDown } from 'lucide-react'
import { cn } from '@/lib/cn'

/**
 * A dashboard fragment: KPI stat tiles + a single-series bar chart. Following
 * the project data-viz method — one hue (Signal Blue) for the single series
 * (no legend; the title names it), recessive neutral gridlines, tabular values,
 * baseline-anchored bars with rounded tops. A visually-hidden table gives the
 * chart a data fallback (FR-DSH-007). Reads identically in light and dark
 * because every color is a token.
 */

const days = [
  { label: 'Mon', short: 'M', value: 24 },
  { label: 'Tue', short: 'T', value: 31 },
  { label: 'Wed', short: 'W', value: 28 },
  { label: 'Thu', short: 'T', value: 35 },
  { label: 'Fri', short: 'F', value: 30 },
  { label: 'Sat', short: 'S', value: 12 },
  { label: 'Sun', short: 'S', value: 8 },
]

const total = days.reduce((sum, d) => sum + d.value, 0)
const peakIndex = days.reduce((best, d, i, arr) => (d.value > arr[best].value ? i : best), 0)

// Chart geometry (viewBox units).
const W = 320
const H = 150
const PAD_L = 24
const PAD_R = 8
const PAD_T = 12
const BASELINE = 118
const Y_MAX = 40
const gridValues = [0, 20, 40]

const plotW = W - PAD_L - PAD_R
const slot = plotW / days.length
const barW = slot - 12

function yFor(value: number): number {
  return BASELINE - (value / Y_MAX) * (BASELINE - PAD_T)
}

function topRoundedBar(x: number, top: number, w: number, r: number): string {
  const h = BASELINE - top
  const radius = Math.min(r, w / 2, h)
  return `M${x},${BASELINE} L${x},${top + radius} Q${x},${top} ${x + radius},${top} L${x + w - radius},${top} Q${x + w},${top} ${x + w},${top + radius} L${x + w},${BASELINE} Z`
}

function StatTile({
  label,
  value,
  children,
}: {
  label: string
  value: string
  children?: ReactNode
}) {
  return (
    <div className="rounded-md border border-border bg-surface-sunken px-3 py-2.5">
      <p className="truncate text-[11px] font-medium text-muted">{label}</p>
      <p className="tnum mt-1 text-lg font-semibold leading-none text-ink-strong">{value}</p>
      {children && <div className="mt-1.5">{children}</div>}
    </div>
  )
}

export function AnalyticsVisual({ className }: { className?: string }) {
  return (
    <figure className={cn('overflow-hidden rounded-lg border border-border bg-surface', className)}>
      <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
        <span className="text-sm font-semibold text-ink-strong">Analytics</span>
        <span className="text-[11px] text-muted">Last 7 days</span>
      </div>

      <div className="p-4">
        <div className="grid grid-cols-3 gap-2.5">
          <StatTile label="Open backlog" value="42">
            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-success-strong">
              <TrendingDown size={12} aria-hidden="true" /> 12%
            </span>
          </StatTile>
          <StatTile label="SLA compliance" value="94.2%">
            <span
              className="block h-1.5 overflow-hidden rounded-full bg-surface"
              role="meter"
              aria-valuenow={94.2}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label="SLA compliance"
            >
              <span className="block h-full rounded-full bg-success" style={{ width: '94.2%' }} />
            </span>
          </StatTile>
          <StatTile label="MTTR" value="4.6h">
            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-success-strong">
              <TrendingDown size={12} aria-hidden="true" /> 0.4h
            </span>
          </StatTile>
        </div>

        <figure className="mt-4">
          <figcaption className="mb-2 flex items-center justify-between">
            <span className="text-[12.5px] font-medium text-ink-strong">
              Tickets resolved per day
            </span>
            <span className="tnum text-[11px] text-muted">Total {total}</span>
          </figcaption>

          <svg
            viewBox={`0 0 ${W} ${H}`}
            className="w-full"
            role="img"
            aria-label={`Bar chart of tickets resolved per day over the last week, ${total} in total; the peak was ${days[peakIndex].value} on ${days[peakIndex].label}.`}
          >
            {/* Recessive gridlines + y labels */}
            {gridValues.map((gv) => {
              const y = yFor(gv)
              return (
                <g key={gv}>
                  <line
                    x1={PAD_L}
                    x2={W - PAD_R}
                    y1={y}
                    y2={y}
                    stroke="var(--border)"
                    strokeWidth={1}
                  />
                  <text
                    x={PAD_L - 6}
                    y={y + 3}
                    textAnchor="end"
                    className="fill-[var(--faint)] text-[8px]"
                    style={{ fontVariantNumeric: 'tabular-nums' }}
                  >
                    {gv}
                  </text>
                </g>
              )
            })}

            {/* Bars */}
            {days.map((d, i) => {
              const x = PAD_L + i * slot + (slot - barW) / 2
              const top = yFor(d.value)
              const isPeak = i === peakIndex
              return (
                <g key={d.label}>
                  <path
                    d={topRoundedBar(x, top, barW, 4)}
                    className={isPeak ? 'fill-primary' : 'fill-primary/55'}
                  />
                  {isPeak && (
                    <text
                      x={x + barW / 2}
                      y={top - 5}
                      textAnchor="middle"
                      className="fill-[var(--ink-strong)] text-[9px] font-semibold"
                      style={{ fontVariantNumeric: 'tabular-nums' }}
                    >
                      {d.value}
                    </text>
                  )}
                  <text
                    x={x + barW / 2}
                    y={BASELINE + 12}
                    textAnchor="middle"
                    className="fill-[var(--muted)] text-[8.5px]"
                  >
                    {d.short}
                  </text>
                </g>
              )
            })}
          </svg>

          {/* Data-table fallback for the chart */}
          <table className="sr-only">
            <caption>Tickets resolved per day over the last week</caption>
            <thead>
              <tr>
                <th scope="col">Day</th>
                <th scope="col">Resolved</th>
              </tr>
            </thead>
            <tbody>
              {days.map((d) => (
                <tr key={d.label}>
                  <th scope="row">{d.label}</th>
                  <td>{d.value}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </figure>
      </div>
    </figure>
  )
}
