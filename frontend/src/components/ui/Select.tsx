import { forwardRef, type SelectHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/**
 * Native select styled to match Input (DESIGN.md control spec). Native on
 * purpose — robust, accessible, keyboard-friendly on all the varied/older
 * hardware the product targets.
 */
export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
  function Select({ className, children, ...props }, ref) {
    return (
      <select
        ref={ref}
        className={cn(
          'h-15 w-full rounded-md border-2 border-control-border bg-surface px-4 text-sm font-medium text-ink',
          'transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
          'focus:border-primary disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-faint',
          'aria-[invalid=true]:border-danger',
          className,
        )}
        {...props}
      >
        {children}
      </select>
    )
  },
)
