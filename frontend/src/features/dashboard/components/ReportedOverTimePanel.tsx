import { Select } from '@/components/ui'
import { TimeSeriesChart, type SeriesPoint } from '@/components/charts'
import type { TicketOverview } from '@/features/analytics/types'
import { WidgetCard } from './WidgetCard'

const UNITS: Array<{ value: string; label: string; maxDays: number }> = [
  { value: 'auto', label: 'Automatic', maxDays: Infinity },
  { value: 'day', label: 'Day', maxDays: 92 },
  { value: 'week', label: 'Week', maxDays: 7 * 104 },
  { value: 'month', label: 'Month', maxDays: 3600 },
  { value: 'year', label: 'Year', maxDays: Infinity },
]

/**
 * "Tickets reported over time" (Phase 2.10): the current period as a solid
 * line, the comparison dashed. Group by is a display option for this chart
 * alone, offering only the units the period can show.
 *
 * A one-day period has one point, and a one-point line says nothing — it is
 * shown as the figure and its comparison in words instead.
 */
export function ReportedOverTimePanel({
  overview,
  granularity,
  onGranularity,
}: {
  overview: TicketOverview
  granularity?: string
  onGranularity: (value: string | null) => void
}) {
  const { series } = overview.data
  const { meta } = overview
  const compared = series.comparison !== null && meta.comparison !== null

  const points: SeriesPoint[] = series.buckets.map((bucket, index) => {
    const other = series.comparison?.buckets[index]
    return {
      key: bucket.key,
      label: bucket.label,
      shortLabel: bucket.short_label,
      current: bucket.count,
      currentInProgress: bucket.in_progress,
      comparison: other ? other.count : null,
      comparisonLabel: other?.label,
    }
  })

  const notes: string[] = []
  if (series.comparison && series.comparison.unplotted.count > 0) {
    notes.push(
      `${series.comparison.unplotted.count} comparison ${series.comparison.unplotted.count === 1 ? 'ticket falls' : 'tickets fall'} on days with no match in this period; ${series.comparison.unplotted.count === 1 ? 'it is' : 'they are'} in the comparison total but not drawn.`,
    )
  }
  if (series.buckets.some((bucket) => bucket.before_records) && meta.data_starts_at) {
    notes.push('The line starts where records begin; earlier days are unknown, not zero.')
  }

  return (
    <WidgetCard
      variant="section"
      title="Tickets reported over time"
      description={`${meta.period.range_label}, by ${series.unit_label}${compared ? `, compared with ${meta.comparison?.range_label}` : ''}`}
      actions={
        series.buckets.length > 1 || granularity ? (
          <label className="flex items-center gap-2 text-sm whitespace-nowrap text-muted">
            Group by
            <Select
              className="h-12 w-36"
              value={granularity ?? 'auto'}
              onChange={(event) =>
                onGranularity(event.target.value === 'auto' ? null : event.target.value)
              }
            >
              {UNITS.filter((unit) => meta.period.days <= unit.maxDays).map((unit) => (
                <option key={unit.value} value={unit.value}>
                  {unit.label}
                </option>
              ))}
            </Select>
          </label>
        ) : undefined
      }
    >
      {series.buckets.length <= 1 ? (
        <OneDay overview={overview} />
      ) : (
        <div className="flex flex-col gap-3">
          <TimeSeriesChart
            points={points}
            currentName={meta.period.range_label}
            comparisonName={compared ? meta.comparison?.range_label : undefined}
            unit="tickets reported"
            label={`Line chart of tickets reported per ${series.unit_label}, ${meta.period.range_label}${compared ? `, with ${meta.comparison?.range_label} for comparison` : ''}. The figures are in the table below it.`}
          />
          {notes.map((note) => (
            <p key={note} className="text-xs text-muted">
              {note}
            </p>
          ))}
        </div>
      )}
    </WidgetCard>
  )
}

function OneDay({ overview }: { overview: TicketOverview }) {
  const { reported } = overview.data.kpis
  const { meta } = overview

  return (
    <div className="flex flex-col gap-2">
      <p className="text-base text-ink">
        <span className="text-2xl font-semibold text-ink-strong tnum">
          {reported.value.toLocaleString()}
        </span>{' '}
        reported on {meta.period.range_label}
        {reported.comparison !== null && meta.comparison
          ? `, against ${reported.comparison.toLocaleString()} on ${meta.comparison.range_label}`
          : ''}
        .
      </p>
      <p className="text-sm text-muted">
        A single day has one point, so there is no line to draw. Choose a week or longer to see a
        trend.
      </p>
    </div>
  )
}
