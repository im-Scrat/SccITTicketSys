import { cn } from '@/lib/cn'
import type { AccountStatus } from '../types'

interface StatusStyle {
  wash: string
  text: string
  dot: string
  label: string
}

/**
 * Account-status pill for User Management. A feature-specific status vocabulary
 * (distinct from the asset online/offline pill): subtle wash + strong same-hue
 * text + a leading dot, never color-only — the label carries the meaning.
 */
const styles: Record<AccountStatus, StatusStyle> = {
  active: {
    wash: 'bg-success-subtle',
    text: 'text-success-strong',
    dot: 'bg-success',
    label: 'Active',
  },
  pending: {
    wash: 'bg-warning-subtle',
    text: 'text-warning-strong',
    dot: 'bg-warning',
    label: 'Pending',
  },
  suspended: {
    wash: 'bg-danger-subtle',
    text: 'text-danger-strong',
    dot: 'bg-danger',
    label: 'Suspended',
  },
  rejected: {
    wash: 'bg-danger-subtle',
    text: 'text-danger-strong',
    dot: 'bg-danger',
    label: 'Rejected',
  },
  inactive: { wash: 'bg-surface-sunken', text: 'text-muted', dot: 'bg-faint', label: 'Inactive' },
}

export function UserStatusBadge({
  status,
  className,
}: {
  status: AccountStatus
  className?: string
}) {
  const style = styles[status]
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
        style.wash,
        style.text,
        className,
      )}
    >
      <span className={cn('size-1.5 rounded-full', style.dot)} aria-hidden="true" />
      {style.label}
    </span>
  )
}
