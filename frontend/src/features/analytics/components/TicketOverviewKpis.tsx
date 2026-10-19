import { StatCard } from '@/components/ui'
import { drilldownHref } from '@/features/analytics/lib/drilldown'
import { changeText, plural } from '@/features/analytics/lib/format'
import type { TicketOverview } from '@/features/analytics/types'

/**
 * The overview's headline figures (Phase 2.10): Reported, Daily average, Still
 * open, Resolved in period. One row, sized by its own width like KpiRow.
 *
 * Shown on Overall reports, under the report filters that scope them. They
 * moved there from the administrator's dashboard (owner request, 2026-10-19):
 * period figures belong with the period being reported on.
 *
 * Changes are written in words ("3 more than 1–18 Sep 2026, up 33%") with a
 * neutral tone — a rise in reports is not a failure and a fall is not a win,
 * so no red or green judgement. A percentage appears only when the server
 * gives one (base of at least 5). Every figure that has a list opens it.
 */
export function TicketOverviewKpis({ overview }: { overview: TicketOverview }) {
  const { kpis } = overview.data
  const { comparison } = overview.meta
  const reported = kpis.reported
  const against = comparison?.range_label ?? ''

  const reportedHint = [
    !comparison
      ? null
      : !comparison.available
        ? comparison.reason === 'status_filter'
          ? 'Not compared while a status filter is on'
          : 'No comparison: before records began'
        : changeText(reported.change, reported.change_pct, against),
    reported.duplicates + reported.cancelled > 0
      ? `includes ${plural(reported.duplicates, 'duplicate')}, ${reported.cancelled} cancelled`
      : null,
  ]
    .filter(Boolean)
    .join('; ')

  const items = [
    {
      key: 'reported',
      label: 'Reported',
      value: reported.value.toLocaleString(),
      hint: reportedHint || undefined,
      to: drilldownHref(reported.drilldown),
    },
    kpis.daily_average && {
      key: 'daily_average',
      label: 'Daily average',
      value: kpis.daily_average.value.toFixed(1),
      hint:
        kpis.daily_average.comparison !== null
          ? `${kpis.daily_average.comparison.toFixed(1)} in ${against}`
          : `over ${plural(kpis.daily_average.days, 'day')}`,
      to: undefined,
    },
    {
      key: 'open_now',
      label: 'Still open',
      value: kpis.open_now.value.toLocaleString(),
      hint:
        [
          kpis.open_now.share_pct !== null
            ? `${kpis.open_now.share_pct.toFixed(1)}% of reported`
            : null,
          kpis.open_now.awaiting_confirmation > 0
            ? `${kpis.open_now.awaiting_confirmation} awaiting confirmation`
            : null,
        ]
          .filter(Boolean)
          .join('; ') || undefined,
      to: drilldownHref(kpis.open_now.drilldown),
    },
    {
      key: 'resolved',
      label: 'Resolved in period',
      value: kpis.resolved_in_period.value.toLocaleString(),
      hint: 'moved to Resolved in the period, whenever reported',
      to: undefined,
    },
  ].filter((item): item is NonNullable<typeof item> => Boolean(item))

  return (
    <div className="@container">
      <ul
        aria-label="Headline figures"
        className={
          items.length === 4
            ? 'grid grid-cols-1 gap-3 @md:grid-cols-2 @4xl:grid-cols-4'
            : 'grid grid-cols-1 gap-3 @2xl:grid-cols-3'
        }
      >
        {items.map((item) => (
          <li key={item.key} className="min-w-0">
            <StatCard label={item.label} value={item.value} hint={item.hint} to={item.to} />
          </li>
        ))}
      </ul>
    </div>
  )
}
