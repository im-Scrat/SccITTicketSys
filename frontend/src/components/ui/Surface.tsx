import type { ElementType, ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface SurfaceProps {
  children: ReactNode
  className?: string
  as?: ElementType
  /** `sunken` uses the recessed tone for insets and code strips. */
  variant?: 'default' | 'sunken'
}

/**
 * A flat panel: `--surface` (or `--surface-sunken`) on a hairline border, no
 * shadow (Flat-Plane Rule — shadow means "floating overlay", which a panel is
 * not). Depth on the page comes from the tonal stack, not elevation.
 */
export function Surface({
  children,
  className,
  as: Tag = 'div',
  variant = 'default',
}: SurfaceProps) {
  return (
    <Tag
      className={cn(
        'rounded-md border border-border',
        variant === 'sunken' ? 'bg-surface-sunken' : 'bg-surface',
        className,
      )}
    >
      {children}
    </Tag>
  )
}
