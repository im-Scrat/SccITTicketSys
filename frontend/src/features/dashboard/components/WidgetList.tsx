import { Link, useNavigate } from 'react-router-dom'
import { isInteractiveTarget, Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { formatDateTime, formatRelative } from '@/lib/datetime'
import type { ListRow } from '../types'

interface WidgetListProps {
  columns: string[]
  rows: ListRow[]
  emptyLabel?: string
}

/**
 * A short, bounded table inside a dashboard panel — a work queue, a reorder list,
 * an activity feed. Timestamp cells are humanised ("3h ago", "in 2d") because on a
 * dashboard the question is "how stale is this" or "how soon is this", not "what
 * was the exact minute"; the exact time is in the element's tooltip, and the ISO
 * value is on a `<time>` so assistive technology and copy/paste get the real one.
 *
 * A row with an `href` opens its record. The first cell is a real link — focusable,
 * announced as a link, and it opens in a new tab on middle-click — and the rest of
 * the row is a larger pointer target for the same destination. A click that began
 * on a nested link or control is left to that control, so the row never navigates
 * on top of it.
 */
export function WidgetList({ columns, rows, emptyLabel }: WidgetListProps) {
  const navigate = useNavigate()

  if (rows.length === 0) {
    return (
      <p className="px-1 py-6 text-center text-sm text-muted">
        {emptyLabel ?? 'Nothing here right now.'}
      </p>
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          {columns.map((column, index) => (
            <Th key={column} className={index === columns.length - 1 ? 'text-right' : undefined}>
              {column}
            </Th>
          ))}
        </Tr>
      </THead>
      <TBody>
        {rows.map((row, rowIndex) => {
          const href = row.href

          return (
            <Tr
              key={row.id ?? rowIndex}
              onClick={
                href
                  ? (event) => {
                      if (!isInteractiveTarget(event)) navigate(href)
                    }
                  : undefined
              }
            >
              {row.cells.map((cell, cellIndex) => {
                const isTimestamp = row.timestamp_cell === cellIndex
                const isLast = cellIndex === row.cells.length - 1
                const text = typeof cell === 'string' ? cell : null

                return (
                  <Td
                    key={cellIndex}
                    className={[
                      cellIndex === 0 ? 'text-ink' : 'text-muted',
                      isLast ? 'text-right' : '',
                      isTimestamp ? 'whitespace-nowrap tnum' : '',
                    ]
                      .filter(Boolean)
                      .join(' ')}
                  >
                    {isTimestamp ? (
                      text ? (
                        <time dateTime={text} title={formatDateTime(text)}>
                          {formatRelative(text)}
                        </time>
                      ) : (
                        '—'
                      )
                    ) : cellIndex === 0 && href ? (
                      <Link
                        to={href}
                        className="rounded-sm font-medium text-ink-strong hover:underline"
                      >
                        {cell ?? '—'}
                      </Link>
                    ) : (
                      (cell ?? '—')
                    )}
                  </Td>
                )
              })}
            </Tr>
          )
        })}
      </TBody>
    </Table>
  )
}
