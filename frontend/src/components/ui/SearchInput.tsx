import { Search, X } from 'lucide-react'
import { cn } from '@/lib/cn'

interface SearchInputProps {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  className?: string
  'aria-label'?: string
}

/**
 * A search field with a leading icon and a clear affordance. Debouncing is the
 * caller's concern (the directory debounces before hitting the server).
 */
export function SearchInput({
  value,
  onChange,
  placeholder = 'Search…',
  className,
  'aria-label': ariaLabel = 'Search',
}: SearchInputProps) {
  return (
    <div className={cn('relative', className)}>
      <Search
        size={16}
        aria-hidden="true"
        className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-muted"
      />
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        aria-label={ariaLabel}
        className={cn(
          'h-[34px] w-full rounded-sm border border-control-border bg-surface pl-8 pr-8 text-sm text-ink',
          'placeholder:text-muted focus:border-primary',
          '[&::-webkit-search-cancel-button]:hidden',
        )}
      />
      {value && (
        <button
          type="button"
          onClick={() => onChange('')}
          aria-label="Clear search"
          className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-sm p-1 text-muted hover:bg-surface-sunken hover:text-ink"
        >
          <X size={14} aria-hidden="true" />
        </button>
      )}
    </div>
  )
}
