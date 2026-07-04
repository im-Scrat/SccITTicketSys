import { forwardRef, type InputHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

/**
 * Text input per DESIGN.md: 34px tall, `--control-border` (≥3:1), 4px radius,
 * `--ink` value on `--surface`, `--muted` placeholder. Focus turns the border
 * `--primary` (the 2px focus ring is applied globally). The error state is
 * driven by `aria-invalid` so a single flag styles the border and is announced.
 */
export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
  function Input({ className, ...props }, ref) {
    return (
      <input
        ref={ref}
        className={cn(
          'h-[34px] w-full rounded-sm border border-control-border bg-surface px-2.5 text-sm text-ink',
          'placeholder:text-muted transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
          'focus:border-primary disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-faint',
          'aria-[invalid=true]:border-danger',
          className,
        )}
        {...props}
      />
    )
  },
)
