import { Link } from 'react-router-dom'
import { statusColor } from './chartTokens'

export interface LegendRow {
  key: string
  label: string
  count: number
  pct: number | null
  href?: string
}

interface ChartLegendTableProps {
  rows: LegendRow[]
  caption: string
  /** Column heading for the counts, e.g. "Tickets". */
  countLabel: string
}

/**
 * The legend that is also the data: swatch, name, count and share for every
 * category with any — a real table, so the accessible reading and the visual
 * reading are one object (the DistributionMeter rule). Categories at zero are
 * folded into one muted line instead of rows of nothing. A row with a link
 * opens the exact list it counts.
 */
export function ChartLegendTable({ rows, caption, countLabel }: ChartLegendTableProps) {
  const present = rows.filter((row) => row.count > 0)
  const absent = rows.filter((row) => row.count === 0)

  return (
    <div className="flex min-w-0 flex-1 flex-col gap-2">
      <table className="w-full border-collapse text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-b border-border text-left text-xs text-muted">
            <th scope="col" className="py-2 pr-3 font-semibold">
              Status
            </th>
            <th scope="col" className="py-2 pr-3 text-right font-semibold">
              {countLabel}
            </th>
            <th scope="col" className="py-2 text-right font-semibold">
              Share
            </th>
          </tr>
        </thead>
        <tbody>
          {present.map((row) => (
            <tr key={row.key} className="border-b border-border last:border-0">
              <th scope="row" className="py-1.5 pr-3 text-left font-normal">
                <span className="flex items-center gap-2.5">
                  <span
                    aria-hidden="true"
                    className="size-3 shrink-0 rounded-sm"
                    style={{ background: statusColor(row.key) }}
                  />
                  {row.href ? (
                    <Link
                      to={row.href}
                      className="rounded-sm text-ink underline-offset-2 hover:underline"
                    >
                      {row.label}
                    </Link>
                  ) : (
                    <span className="text-ink">{row.label}</span>
                  )}
                </span>
              </th>
              <td className="py-1.5 pr-3 text-right text-ink-strong tnum">
                {row.count.toLocaleString()}
              </td>
              <td className="py-1.5 text-right text-muted tnum">
                {row.pct === null ? '—' : `${row.pct.toFixed(1)}%`}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {absent.length > 0 && present.length > 0 && (
        <p className="text-xs text-muted">
          Not present: {absent.map((row) => row.label).join(', ')}
        </p>
      )}
    </div>
  )
}
