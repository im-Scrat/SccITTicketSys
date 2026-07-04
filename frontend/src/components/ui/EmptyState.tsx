import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface EmptyStateProps {
  /** Icon element (e.g. a lucide icon at ~22px). */
  icon?: ReactNode
  title: string
  /** Teaches what belongs here and what happens next — never a bare "No data". */
  description?: string
  /** Primary next action. */
  action?: ReactNode
  className?: string
}

/**
 * The teaching empty state (DESIGN.md): explains what will appear here and
 * offers the next action, rather than a dead-end "No results". Reused by the
 * app for empty queues, and here for the public status surface.
 */
export function EmptyState({ icon, title, description, action, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center rounded-md border border-dashed border-border bg-surface px-6 py-12 text-center',
        className,
      )}
    >
      {icon && (
        <div
          className="mb-4 flex size-11 items-center justify-center rounded-md bg-surface-sunken text-muted"
          aria-hidden="true"
        >
          {icon}
        </div>
      )}
      <p className="text-[0.9375rem] font-semibold text-ink-strong">{title}</p>
      {description && <p className="mt-1.5 max-w-sm text-sm text-muted">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}
