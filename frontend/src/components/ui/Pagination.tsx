import { ChevronLeft, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/cn'

interface PaginationProps {
  page: number
  lastPage: number
  total: number
  from: number | null
  to: number | null
  onPage: (page: number) => void
}

/**
 * Server-side pager: shows the current window ("1–20 of 137") and prev/next
 * controls. Counts use tabular figures so they don't jitter between pages.
 */
export function Pagination({ page, lastPage, total, from, to, onPage }: PaginationProps) {
  if (total === 0) return null

  return (
    <div className="flex flex-col items-center justify-between gap-3 border-t border-border px-3 py-3 sm:flex-row">
      <p className="text-xs text-muted tnum">
        Showing <span className="text-ink">{from ?? 0}</span>–
        <span className="text-ink">{to ?? 0}</span> of <span className="text-ink">{total}</span>
      </p>
      <div className="flex items-center gap-1">
        <PagerButton onClick={() => onPage(page - 1)} disabled={page <= 1} label="Previous page">
          <ChevronLeft size={16} aria-hidden="true" />
        </PagerButton>
        <span className="px-2 text-xs text-muted tnum" aria-live="polite">
          Page {page} of {lastPage}
        </span>
        <PagerButton onClick={() => onPage(page + 1)} disabled={page >= lastPage} label="Next page">
          <ChevronRight size={16} aria-hidden="true" />
        </PagerButton>
      </div>
    </div>
  )
}

function PagerButton({
  children,
  onClick,
  disabled,
  label,
}: {
  children: React.ReactNode
  onClick: () => void
  disabled: boolean
  label: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      className={cn(
        'flex size-8 items-center justify-center rounded-sm border border-border text-ink',
        'hover:bg-surface-sunken disabled:cursor-not-allowed disabled:text-faint disabled:hover:bg-transparent',
      )}
    >
      {children}
    </button>
  )
}
