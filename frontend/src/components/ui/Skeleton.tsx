import type { CSSProperties } from 'react'
import { cn } from '@/lib/cn'

interface SkeletonProps {
  className?: string
  /** Inline width (number → px). */
  width?: number | string
  /** Inline height (number → px). */
  height?: number | string
  /** Full-radius pill (for avatars/dots). */
  circle?: boolean
  style?: CSSProperties
}

/**
 * Loading placeholder — a recessed block that pulses. Decorative (aria-hidden);
 * the surrounding region owns the accessible loading announcement. Under
 * reduced-motion the pulse is frozen by the global rule.
 */
export function Skeleton({ className, width, height, circle = false, style }: SkeletonProps) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'block animate-pulse bg-surface-sunken',
        circle ? 'rounded-full' : 'rounded',
        className,
      )}
      style={{ width, height, ...style }}
    />
  )
}

/** A stack of text-line skeletons; the last line is shortened for realism. */
export function SkeletonText({ lines = 3, className }: { lines?: number; className?: string }) {
  return (
    <span className={cn('block space-y-2', className)} aria-hidden="true">
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} height={10} width={i === lines - 1 ? '60%' : '100%'} />
      ))}
    </span>
  )
}
