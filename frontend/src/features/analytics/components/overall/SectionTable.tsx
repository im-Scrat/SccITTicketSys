import { Link } from 'react-router-dom'
import { cn } from '@/lib/cn'
import { drilldownHref } from '../../lib/drilldown'
import type { Drilldown } from '../../types'

interface SimpleTableProps {
  title: string
  columns: Array<{ key: string; label: string; type: string }>
  rows: Array<Array<string | number | null>>
  links?: Array<Drilldown | null> | null
  note?: string | null
  /** Rendered between the title and the table (sort / page controls). */
  caption?: boolean
  headerCell?: (column: { key: string; label: string; type: string }) => React.ReactNode
}

const numeric = (type: string) => type === 'number' || type === 'percent'

/**
 * A report table: header row, numbers right-aligned with tabular figures,
 * codes in mono, the first cell a link when the row opens the list it counts.
 * Scrolls inside its own frame on narrow screens; text wraps rather than being
 * clipped.
 */
export function SectionTable({ title, columns, rows, links, note, headerCell }: SimpleTableProps) {
  if (rows.length === 0) {
    return (
      <div className="flex flex-col gap-1">
        <h3 className="text-base font-semibold text-ink-strong">{title}</h3>
        <p className="text-sm text-muted">Nothing recorded for this period.</p>
      </div>
    )
  }

  return (
    <div className="flex min-w-0 flex-col gap-2">
      <h3 className="text-base font-semibold text-ink-strong">{title}</h3>
      <div className="overflow-x-auto rounded-md border border-border">
        <table className="w-full border-collapse text-sm">
          <thead className="bg-surface-sunken">
            <tr>
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  className={cn(
                    'px-3 py-2 text-left font-semibold text-ink',
                    numeric(column.type) && 'text-right',
                  )}
                >
                  {headerCell ? headerCell(column) : column.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, r) => {
              const href = drilldownHref(links?.[r] ?? null)
              return (
                <tr key={r} className="border-t border-border align-top">
                  {columns.map((column, c) => {
                    const value = row[c]
                    const text =
                      typeof value === 'number' ? value.toLocaleString('en-GB') : (value ?? '')
                    return (
                      <td
                        key={column.key}
                        className={cn(
                          'px-3 py-2 text-ink',
                          numeric(column.type) && 'text-right tabular-nums whitespace-nowrap',
                          column.type === 'code' && 'font-mono whitespace-nowrap',
                        )}
                      >
                        {c === 0 && href ? (
                          <Link
                            to={href}
                            className="font-medium text-primary-strong underline-offset-4 hover:underline"
                          >
                            {text}
                          </Link>
                        ) : (
                          text
                        )}
                      </td>
                    )
                  })}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      {note && <p className="text-xs text-muted">{note}</p>}
    </div>
  )
}
