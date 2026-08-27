import { Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { formatRelative } from '@/lib/datetime'
import type { ListRow } from '../types'

interface WidgetListProps {
  columns: string[]
  rows: ListRow[]
  emptyLabel?: string
}

/**
 * A short, bounded table inside a dashboard panel — a work queue, a reorder list,
 * an activity feed. Timestamp cells are humanised ("3h ago") because on a
 * dashboard the question is "how stale is this", not "what was the exact minute";
 * the full value is on the record itself.
 */
export function WidgetList({ columns, rows, emptyLabel }: WidgetListProps) {
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
        {rows.map((row, rowIndex) => (
          <Tr key={row.id ?? rowIndex}>
            {row.cells.map((cell, cellIndex) => {
              const isTimestamp = row.timestamp_cell === cellIndex
              const isLast = cellIndex === row.cells.length - 1

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
                  {isTimestamp
                    ? formatRelative(typeof cell === 'string' ? cell : null)
                    : (cell ?? '—')}
                </Td>
              )
            })}
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}
