import { cn } from '@/lib/cn'
import type { DistributionRow } from '../types'

interface DistributionMeterProps {
  rows: DistributionRow[]
  /** Plural noun for the caption, e.g. "tickets". */
  unit?: string
  emptyLabel?: string
}

/**
 * A labelled magnitude distribution — how many items sit in each status,
 * priority or bucket.
 *
 * **It is a table, and the bars are decoration.** The markup is a real
 * `<table>`: the label is a row header, the count is a text cell, and the bar is
 * `aria-hidden` width. That makes the accessible reading and the visual reading
 * the same object — there is no separate "table view" to fall out of sync, and
 * nothing is conveyed by colour or length alone.
 *
 * Every bar uses the **same** hue. Comparing magnitude is the job here, and the
 * categories (statuses, priorities, people) have no inherent order, so shading
 * them by size would double-encode length as colour and spend the one free
 * channel on information the bar already carries. The track is a lighter step of
 * that same hue, so a short bar still reads as "of this scale".
 *
 * Widths are proportional to the largest row, which is what makes small
 * differences legible; the caption carries the total so the part-to-whole reading
 * is still available.
 */
export function DistributionMeter({ rows, unit, emptyLabel }: DistributionMeterProps) {
  const total = rows.reduce((sum, row) => sum + row.count, 0)
  const max = rows.reduce((peak, row) => Math.max(peak, row.count), 0)

  if (rows.length === 0 || total === 0) {
    return (
      <p className="px-1 py-6 text-center text-sm text-muted">
        {emptyLabel ?? `Nothing to show yet.`}
      </p>
    )
  }

  return (
    <table className="w-full border-collapse text-sm">
      <caption className="caption-bottom pt-3 text-left text-xs text-muted tnum">
        {total.toLocaleString()} {unit ?? 'items'} in total
      </caption>
      <thead className="sr-only">
        <tr>
          <th scope="col">Category</th>
          <th scope="col">Share</th>
          <th scope="col">Count</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => {
          const share = max === 0 ? 0 : Math.round((row.count / max) * 100)

          return (
            <tr key={row.key}>
              <th
                scope="row"
                className="w-[38%] max-w-[12rem] truncate py-1.5 pr-3 text-left text-sm font-normal text-ink"
              >
                {row.label}
              </th>
              <td className="py-1.5" aria-hidden="true">
                {/* Track: a lighter step of the bar's own hue. */}
                <div className="h-2 w-full rounded-sm bg-primary-subtle">
                  <div
                    className={cn(
                      'h-2 rounded-r-sm bg-primary',
                      // Square at the baseline, rounded at the data end.
                      row.count === 0 && 'rounded-none',
                    )}
                    style={{ width: `${share}%` }}
                  />
                </div>
              </td>
              <td className="w-14 py-1.5 pl-3 text-right text-sm text-ink-strong tnum">
                {row.count.toLocaleString()}
              </td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}
