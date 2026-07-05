import { forwardRef, type TextareaHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/**
 * Multi-line text control styled to match {@link Input} (DESIGN.md control spec):
 * `--control-border`, 4px radius, `--ink` on `--surface`, error via aria-invalid.
 */
export const Textarea = forwardRef<
  HTMLTextAreaElement,
  TextareaHTMLAttributes<HTMLTextAreaElement>
>(function Textarea({ className, rows = 3, ...props }, ref) {
  return (
    <textarea
      ref={ref}
      rows={rows}
      className={cn(
        'w-full rounded-sm border border-control-border bg-surface px-2.5 py-1.5 text-sm text-ink',
        'placeholder:text-muted transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
        'focus:border-primary disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-faint',
        'aria-[invalid=true]:border-danger',
        className,
      )}
      {...props}
    />
  )
})
