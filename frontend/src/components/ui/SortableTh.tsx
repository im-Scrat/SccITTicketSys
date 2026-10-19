import { ArrowDown, ArrowUp } from 'lucide-react'
import { Th } from '@/components/ui'
import { cn } from '@/lib/cn'

interface SortableThProps<T extends string> {
  label: string
  column: T
  sort: T
  direction: 'asc' | 'desc'
  onSort: (column: T) => void
  className?: string
  /** Right-align numeric columns (DESIGN.md — the Tabular-Truth rule). */
  numeric?: boolean
}

/**
 * A column header that sorts. `aria-sort` tells assistive technology the current
 * order, and the arrow is decorative — the state is never conveyed by the icon
 * alone.
 */
export function SortableTh<T extends string>({
  label,
  column,
  sort,
  direction,
  onSort,
  className,
  numeric,
}: SortableThProps<T>) {
  const active = sort === column

  return (
    <Th
      className={cn(numeric && 'text-right', className)}
      aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
    >
      <button
        type="button"
        onClick={() => onSort(column)}
        className={cn(
          'inline-flex items-center gap-1 font-semibold hover:text-ink',
          active ? 'text-ink' : 'text-muted',
        )}
      >
        {label}
        {active &&
          (direction === 'asc' ? (
            <ArrowUp size={13} aria-hidden="true" />
          ) : (
            <ArrowDown size={13} aria-hidden="true" />
          ))}
      </button>
    </Th>
  )
}
