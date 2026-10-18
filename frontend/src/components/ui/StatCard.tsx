import { ChevronRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
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
  /**
   * When set, the whole card becomes a **link** to the list this figure counts.
   * Navigation is a link, not a button: it can be opened in a new tab, copied, and
   * is announced as a link. Wins over `onClick` if both are given.
   */
  to?: string
  active?: boolean
}

/**
 * A single dashboard metric: quiet label, prominent tabular value. Optional
 * `onClick` turns it into a filter affordance; `to` turns it into a link;
 * `active` shows the applied state.
 */
export function StatCard({
  label,
  value,
  icon,
  tone = 'neutral',
  hint,
  onClick,
  to,
  active,
}: StatCardProps) {
  const content = (
    <>
      <div className="flex items-start justify-between gap-2">
        <span className="text-xs font-medium text-muted">{label}</span>
        {icon && <span className="shrink-0 text-muted">{icon}</span>}
        {to && !icon && (
          <ChevronRight size={16} aria-hidden="true" className="shrink-0 text-muted" />
        )}
      </div>
      <div className={cn('mt-1 text-2xl font-semibold tracking-[-0.02em] tnum', toneText[tone])}>
        {value}
      </div>
      {hint && <p className="mt-0.5 text-xs text-muted">{hint}</p>}
    </>
  )

  const base = 'block h-full min-w-0 rounded-md border bg-surface px-3.5 py-3 text-left'

  if (to) {
    // The visible text is "label" and "value" in separate elements; reading them
    // as one phrase ("Overdue: 2") is what a screen-reader user needs from a
    // link, so the name is set explicitly.
    // The hint rides along: an explicit name replaces the content, so without
    // it "▲ 3 vs last month" would be visible but never announced.
    const name =
      typeof value === 'string' || typeof value === 'number'
        ? `${label}: ${value}${hint ? `. ${hint}` : ''}`
        : undefined

    return (
      <Link
        to={to}
        aria-label={name}
        className={cn(base, 'border-border transition-colors hover:bg-surface-sunken')}
      >
        {content}
      </Link>
    )
  }

  if (onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        aria-pressed={active}
        className={cn(
          base,
          'w-full transition-colors hover:bg-surface-sunken',
          active ? 'border-primary ring-1 ring-primary' : 'border-border',
        )}
      >
        {content}
      </button>
    )
  }

  return <div className={cn(base, 'border-border')}>{content}</div>
}
