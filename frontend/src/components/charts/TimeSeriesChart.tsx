import { CartesianGrid, Line, LineChart, Tooltip, XAxis, YAxis } from 'recharts'
import type { TooltipContentProps } from 'recharts'
import { CHART } from './chartTokens'
import { ChartFrame } from './ChartFrame'

export interface SeriesPoint {
  key: string
  /** Full label for the tooltip and table ("Tue 6 Oct 2026"). */
  label: string
  /** Axis label ("6 Oct"). */
  shortLabel: string
  /** Null draws a gap: unknown (before records), never zero. */
  current: number | null
  currentInProgress?: boolean
  comparison?: number | null
  comparisonLabel?: string
}

interface TimeSeriesChartProps {
  points: SeriesPoint[]
  /** "1–18 Oct 2026" — names the solid line in the legend. */
  currentName: string
  /** "1–18 Sep 2026" — names the dashed line; omit when there is no comparison. */
  comparisonName?: string
  /** What one point counts, e.g. "tickets reported". */
  unit: string
  /** Sentence naming the chart for assistive technology. */
  label: string
}

/**
 * A count over time with an optional comparison period (Phase 2.10).
 *
 * The current period is a 2px line in the selection colour; the comparison is
 * dashed (6 4) in a receding grey, so the two differ in shape as well as colour
 * and survive grayscale. No area fill and no animation: the line is honest for
 * discrete counts, and figures should not move while someone reads them. The
 * bucket still in progress gets a hollow point. Axis ticks stay at 16px and at
 * least ~72px apart, never rotated.
 */
export function TimeSeriesChart({
  points,
  currentName,
  comparisonName,
  unit,
  label,
}: TimeSeriesChartProps) {
  const compared = comparisonName !== undefined
  // Taller where the panel beside it (the status donut) is tall, shorter on phones.
  const viewport = typeof window !== 'undefined' ? window.innerWidth : 1024
  const height = viewport < 640 ? 260 : viewport >= 1280 ? 400 : 320

  return (
    <div className="flex min-w-0 flex-col gap-3">
      <ul className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted" aria-label="Lines">
        <li className="flex items-center gap-2">
          <LegendKey />
          <span className="text-ink">{currentName}</span>
        </li>
        {compared && (
          <li className="flex items-center gap-2">
            <LegendKey dashed />
            <span>{comparisonName}</span>
          </li>
        )}
      </ul>

      <ChartFrame
        height={height}
        label={label}
        table={<SeriesTable points={points} compared={compared} unit={unit} />}
      >
        {(width) => (
          <LineChart
            width={width}
            height={height}
            data={points}
            margin={{ top: 8, right: 16, bottom: 4, left: 0 }}
            // Off: the drawing is hidden from assistive technology and its
            // table twin carries the figures, so a focusable layer inside the
            // hidden region would be a trap (axe: aria-hidden-focus).
            accessibilityLayer={false}
          >
            <CartesianGrid vertical={false} stroke={CHART.grid} />
            <XAxis
              dataKey="shortLabel"
              tick={{ fontSize: CHART.fontSize, fill: CHART.muted }}
              tickLine={false}
              axisLine={{ stroke: CHART.axis }}
              interval="preserveStartEnd"
              minTickGap={24}
              tickMargin={8}
            />
            <YAxis
              allowDecimals={false}
              width={48}
              tick={{ fontSize: CHART.fontSize, fill: CHART.muted }}
              tickLine={false}
              axisLine={false}
              tickFormatter={compactTick}
            />
            <Tooltip
              cursor={{ stroke: CHART.axis }}
              isAnimationActive={false}
              content={(props) => <SeriesTooltip {...props} unit={unit} compared={compared} />}
            />
            {compared && (
              <Line
                type="linear"
                dataKey="comparison"
                stroke={CHART.compare}
                strokeWidth={2}
                strokeDasharray={CHART.compareDash}
                dot={false}
                activeDot={{ r: 4 }}
                isAnimationActive={false}
                connectNulls={false}
              />
            )}
            <Line
              type="linear"
              dataKey="current"
              stroke={CHART.current}
              strokeWidth={2}
              dot={(props: { cx?: number; cy?: number; index: number }) => (
                <CurrentDot key={props.index} {...props} points={points} />
              )}
              activeDot={{ r: 5 }}
              isAnimationActive={false}
              connectNulls={false}
            />
          </LineChart>
        )}
      </ChartFrame>
    </div>
  )
}

/** Thousands abbreviated only on axis ticks, and only from 10,000. */
function compactTick(value: number): string {
  return value >= 10_000
    ? new Intl.NumberFormat('en', { notation: 'compact' }).format(value)
    : value.toLocaleString()
}

function CurrentDot({
  cx,
  cy,
  index,
  points,
}: {
  cx?: number
  cy?: number
  index: number
  points: SeriesPoint[]
}) {
  const point = points[index]
  if (cx === undefined || cy === undefined || point?.current === null) return null

  if (point?.currentInProgress) {
    // Hollow: this count is "so far".
    return (
      <circle cx={cx} cy={cy} r={4} fill={CHART.surface} stroke={CHART.current} strokeWidth={2} />
    )
  }

  // Points only when there are few enough to read; otherwise the line carries it.
  return points.length <= 31 ? <circle cx={cx} cy={cy} r={3} fill={CHART.current} /> : null
}

function LegendKey({ dashed = false }: { dashed?: boolean }) {
  return (
    <svg width="28" height="8" aria-hidden="true" className="shrink-0">
      <line
        x1="0"
        y1="4"
        x2="28"
        y2="4"
        stroke={dashed ? CHART.compare : CHART.current}
        strokeWidth="2"
        strokeDasharray={dashed ? CHART.compareDash : undefined}
      />
    </svg>
  )
}

function SeriesTooltip({
  active,
  payload,
  unit,
  compared,
}: TooltipContentProps & { unit: string; compared: boolean }) {
  const point = payload?.[0]?.payload as SeriesPoint | undefined
  if (!active || !point) return null

  return (
    <div className="max-w-xs rounded-md border border-border bg-surface px-3 py-2 text-xs shadow-sm">
      <p className="font-semibold text-ink-strong">{point.label}</p>
      <p className="mt-1 text-ink tnum">
        <span className="text-sm font-semibold">
          {point.current === null ? 'No records yet' : point.current.toLocaleString()}
        </span>{' '}
        {point.current !== null && unit}
        {point.currentInProgress && <span className="text-muted"> (so far)</span>}
      </p>
      {compared && (
        <p className="mt-1 text-muted tnum">
          {point.comparison === null || point.comparison === undefined
            ? `${point.comparisonLabel ?? 'Comparison'}: no matching day`
            : `${point.comparisonLabel ?? 'Comparison'}: ${point.comparison.toLocaleString()}`}
        </p>
      )}
    </div>
  )
}

function SeriesTable({
  points,
  compared,
  unit,
}: {
  points: SeriesPoint[]
  compared: boolean
  unit: string
}) {
  return (
    <table className="w-full min-w-[20rem] border-collapse text-sm">
      <thead>
        <tr className="border-b border-border text-left text-xs text-muted">
          <th scope="col" className="py-2 pr-4 font-semibold">
            Period
          </th>
          <th scope="col" className="py-2 pr-4 text-right font-semibold">
            {unit.charAt(0).toUpperCase() + unit.slice(1)}
          </th>
          {compared && (
            <th scope="col" className="py-2 text-right font-semibold">
              Comparison
            </th>
          )}
        </tr>
      </thead>
      <tbody>
        {points.map((point) => (
          <tr key={point.key} className="border-b border-border last:border-0">
            <th scope="row" className="py-1.5 pr-4 text-left font-normal text-ink">
              {point.label}
              {point.currentInProgress && <span className="text-muted"> (so far)</span>}
            </th>
            <td className="py-1.5 pr-4 text-right text-ink-strong tnum">
              {point.current === null ? '—' : point.current.toLocaleString()}
            </td>
            {compared && (
              <td className="py-1.5 text-right text-muted tnum">
                {point.comparison === null || point.comparison === undefined
                  ? '—'
                  : `${point.comparison.toLocaleString()}${point.comparisonLabel ? ` (${point.comparisonLabel})` : ''}`}
              </td>
            )}
          </tr>
        ))}
      </tbody>
    </table>
  )
}
