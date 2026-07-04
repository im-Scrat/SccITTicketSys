import { cn } from '@/lib/cn'

export type AssetStatus = 'online' | 'offline' | 'maintenance' | 'assigned' | 'available'

interface StatusStyle {
  label: string
  wash: string
  text: string
  dot: string
}

/**
 * The one status vocabulary shared across tickets, assets, maintenance, and the
 * floor plan. Subtle wash + strong same-hue text + an 8px leading dot — never
 * color-only; the text label carries the meaning so it survives color-blindness
 * and grayscale. Status hues are universal and never re-skinned.
 */
const styles: Record<AssetStatus, StatusStyle> = {
  online: {
    label: 'Online',
    wash: 'bg-success-subtle',
    text: 'text-success-strong',
    dot: 'bg-success',
  },
  offline: {
    label: 'Offline',
    wash: 'bg-danger-subtle',
    text: 'text-danger-strong',
    dot: 'bg-danger',
  },
  maintenance: {
    label: 'Under maintenance',
    wash: 'bg-warning-subtle',
    text: 'text-warning-strong',
    dot: 'bg-warning',
  },
  assigned: {
    label: 'Assigned',
    wash: 'bg-primary-subtle',
    text: 'text-primary-strong',
    dot: 'bg-primary',
  },
  available: {
    label: 'Available',
    wash: 'bg-surface-sunken',
    text: 'text-muted',
    dot: 'bg-faint',
  },
}

interface StatusPillProps {
  status: AssetStatus
  /** Override the default label (e.g. a shorter form on dense mocks). */
  label?: string
  className?: string
}

export function StatusPill({ status, label, className }: StatusPillProps) {
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
      {label ?? style.label}
    </span>
  )
}
