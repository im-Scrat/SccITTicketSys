import { ChartLegendTable, DonutChart } from '@/components/charts'
import { drilldownHref } from '@/features/analytics/lib/drilldown'
import type { TicketOverview } from '@/features/analytics/types'
import { WidgetCard } from './WidgetCard'

/**
 * "Current status of tickets reported …" (Phase 2.10): the requested pie, as a
 * donut beside a legend table that carries every count and share. The title
 * says what is measured — the status these tickets have **now**, not their
 * status at the time — so the figure cannot be misread as throughput.
 */
export function StatusDistributionPanel({ overview }: { overview: TicketOverview }) {
  const { status_distribution: distribution } = overview.data
  const range = overview.meta.period.range_label.replace(' (to date)', '')
  const only = distribution.rows.filter((row) => row.count > 0)

  return (
    <WidgetCard
      variant="section"
      title={`Current status of tickets reported ${range}`}
      description={
        only.length === 1
          ? `All ${distribution.total.toLocaleString()} are ${only[0].label}.`
          : 'Each ticket counted once, by the status it has now.'
      }
    >
      {distribution.total === 0 ? (
        <p className="text-sm text-muted">No tickets were reported in this period.</p>
      ) : (
        <div className="@container">
          <div className="flex flex-col items-center gap-6 @xl:flex-row @xl:items-start">
            <DonutChart
              size={180}
              centreLabel={distribution.total === 1 ? 'ticket' : 'tickets'}
              slices={distribution.rows.map((row) => ({
                key: row.slug,
                label: row.label,
                count: row.count,
              }))}
              label={`Donut chart of the current status of ${distribution.total} tickets. The figures are in the table beside it.`}
            />
            <ChartLegendTable
              caption="Tickets by current status"
              countLabel="Tickets"
              rows={distribution.rows.map((row) => ({
                key: row.slug,
                label: row.label,
                count: row.count,
                pct: row.pct,
                href: drilldownHref(row.drilldown),
              }))}
            />
          </div>
        </div>
      )}
    </WidgetCard>
  )
}
