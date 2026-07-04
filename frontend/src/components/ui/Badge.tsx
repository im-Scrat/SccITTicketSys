import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export type BadgeTone = 'neutral' | 'primary' | 'info' | 'success' | 'warning' | 'outline'

const tones: Record<BadgeTone, string> = {
  neutral: 'bg-surface-sunken text-muted',
  primary: 'bg-primary-subtle text-primary-strong',
  info: 'bg-info-subtle text-info',
  success: 'bg-success-subtle text-success-strong',
  warning: 'bg-warning-subtle text-warning-strong',
  outline: 'border border-border text-muted',
}

interface BadgeProps {
  children: ReactNode
  tone?: BadgeTone
  className?: string
  /** Optional leading icon (sized ~13px). */
  icon?: ReactNode
}

/**
 * A small, quiet label — feature tags, roadmap markers, inline metadata. Not a
 * status signal; use {@link StatusPill} for the online/offline/maintenance
 * vocabulary that carries operational meaning.
 */
export function Badge({ children, tone = 'neutral', className, icon }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
        tones[tone],
        className,
      )}
    >
      {icon}
      {children}
    </span>
  )
}
