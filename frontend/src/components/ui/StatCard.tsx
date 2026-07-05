import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export type StatTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info'

const toneText: Record<StatTone, string> = {
  neutral: 'text-ink-strong',
  primary: 'text-primary-strong',
  success: 'text-success-strong',
  warning: 'text-warning-strong',
  danger: 'text-danger-strong',
  info: 'text-info',
}

interface StatCardProps {
  label: string
  value: ReactNode
  icon?: ReactNode
  tone?: StatTone
  hint?: string
  /** When set, the whole card becomes a button (e.g. filter by this status). */
  onClick?: () => void
  active?: boolean
}

/**
 * A single dashboard metric: quiet label, prominent tabular value. Optional
 * `onClick` turns it into a filter affordance; `active` shows the applied state.
 */
export function StatCard({
  label,
  value,
  icon,
  tone = 'neutral',
  hint,
  onClick,
  active,
}: StatCardProps) {
  const content = (
    <>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-medium text-muted">{label}</span>
        {icon && <span className="text-muted">{icon}</span>}
      </div>
      <div className={cn('mt-1 text-2xl font-semibold tracking-[-0.02em] tnum', toneText[tone])}>
        {value}
      </div>
      {hint && <p className="mt-0.5 text-xs text-muted">{hint}</p>}
    </>
  )

  const base = 'rounded-md border bg-surface px-3.5 py-3 text-left'

  if (onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        aria-pressed={active}
        className={cn(
          base,
          'transition-colors hover:bg-surface-sunken',
          active ? 'border-primary ring-1 ring-primary' : 'border-border',
        )}
      >
        {content}
      </button>
    )
  }

  return <div className={cn(base, 'border-border')}>{content}</div>
}
