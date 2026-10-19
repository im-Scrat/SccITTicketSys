import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

export interface RankingItem {
  key: string
  rank: number
  label: string
  /** A second line: building, type, "archived". */
  detail?: ReactNode
  count: number
  href?: string
}

interface RankingBarsProps {
  items: RankingItem[]
  caption: string
  /** Column heading for the counts, e.g. "Problems". */
  countLabel: string
  nameLabel: string
}

/**
 * A ranking as a table with bars (Phase 2.10): long room names **wrap** in
 * full instead of being clipped, keyboard and screen-reader reading is native,
 * and each row links to the exact list it counts. One mark colour for every
 * bar — the rows are the same kind of thing, so colour would only repeat what
 * the length already says. Widths are proportional to the largest row.
 */
export function RankingBars({ items, caption, countLabel, nameLabel }: RankingBarsProps) {
  const max = items.reduce((peak, item) => Math.max(peak, item.count), 0)

  return (
    <table className="w-full border-collapse text-sm">
      <caption className="sr-only">{caption}</caption>
      <thead>
        <tr className="border-b border-border text-left text-xs text-muted">
          <th scope="col" className="w-10 py-2 pr-2 font-semibold">
            <span className="sr-only">Rank</span>
            <span aria-hidden="true">#</span>
          </th>
          <th scope="col" className="py-2 pr-3 font-semibold">
            {nameLabel}
          </th>
          <th scope="col" className="hidden w-[32%] py-2 pr-3 font-semibold sm:table-cell">
            <span className="sr-only">Share of the largest</span>
          </th>
          <th scope="col" className="w-16 py-2 text-right font-semibold">
            {countLabel}
          </th>
        </tr>
      </thead>
      <tbody>
        {items.map((item) => (
          <tr key={item.key} className="border-b border-border align-top last:border-0">
            <td className="py-2.5 pr-2 text-muted tnum">{item.rank}</td>
            <th scope="row" className="py-2.5 pr-3 text-left font-normal">
              {item.href ? (
                <Link
                  to={item.href}
                  className="rounded-sm font-medium text-ink underline-offset-2 hover:underline"
                >
                  {item.label}
                </Link>
              ) : (
                <span className="font-medium text-ink">{item.label}</span>
              )}
              {item.detail && <div className="mt-0.5 text-xs text-muted">{item.detail}</div>}
            </th>
            <td className="hidden py-3.5 pr-3 sm:table-cell" aria-hidden="true">
              <div className="h-2.5 w-full rounded-sm bg-[var(--chart-track)]">
                <div
                  className="h-2.5 rounded-sm bg-[var(--chart-bar)]"
                  style={{ width: `${max === 0 ? 0 : Math.max(2, (item.count / max) * 100)}%` }}
                />
              </div>
            </td>
            <td className="py-2.5 text-right text-ink-strong tnum">
              {item.count.toLocaleString()}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
