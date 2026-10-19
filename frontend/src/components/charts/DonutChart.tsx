import { Cell, Pie, PieChart } from 'recharts'
import { CHART, statusColor } from './chartTokens'

export interface DonutSlice {
  key: string
  label: string
  count: number
}

interface DonutChartProps {
  slices: DonutSlice[]
  /** Diameter in px. */
  size: number
  /** Under the total in the centre, e.g. "tickets". */
  centreLabel: string
  /** A sentence for assistive technology; the legend table carries the figures. */
  label: string
}

/**
 * A part-to-whole ring for ticket statuses (owner-requested pie, Phase 2.10).
 *
 * Drawn clockwise from twelve o'clock, slices separated by a 2px gap in the
 * surface colour, colour by status slug. No labels on the slices: the legend
 * table beside it names every status with its count and share, which is
 * readable at any size and by any eye. One status alone is still a full ring,
 * with the centre saying so, rather than a chart that vanishes.
 */
export function DonutChart({ slices, size, centreLabel, label }: DonutChartProps) {
  const shown = slices.filter((slice) => slice.count > 0)
  const total = shown.reduce((sum, slice) => sum + slice.count, 0)

  return (
    <div
      role="img"
      aria-label={label}
      className="relative shrink-0"
      style={{ width: size, height: size }}
    >
      <div aria-hidden="true">
        <PieChart width={size} height={size} accessibilityLayer={false}>
          <Pie
            data={shown.length > 0 ? shown : [{ key: 'none', label: 'None', count: 1 }]}
            dataKey="count"
            nameKey="label"
            cx="50%"
            cy="50%"
            innerRadius="64%"
            outerRadius="100%"
            startAngle={90}
            endAngle={-270}
            stroke={CHART.surface}
            strokeWidth={shown.length > 1 ? 2 : 0}
            isAnimationActive={false}
            // The Pie's own root layer is tabIndex 0 by default, independent of
            // the chart's accessibilityLayer. Inside this aria-hidden wrapper
            // it would be a focus stop with no name (axe: aria-hidden-focus);
            // the legend table is the keyboard and screen-reader path.
            rootTabIndex={-1}
          >
            {(shown.length > 0 ? shown : [{ key: 'none' }]).map((slice) => (
              <Cell
                key={slice.key}
                fill={slice.key === 'none' ? CHART.track : statusColor(slice.key)}
              />
            ))}
          </Pie>
        </PieChart>
      </div>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center"
      >
        <span className="text-2xl font-semibold text-ink-strong tnum">
          {total.toLocaleString()}
        </span>
        <span className="text-xs text-muted">{centreLabel}</span>
      </div>
    </div>
  )
}
