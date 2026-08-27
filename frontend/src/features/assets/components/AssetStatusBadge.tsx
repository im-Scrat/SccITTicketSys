import { cn } from '@/lib/cn'
import type { Tone } from '../types'

const toneStyles: Record<Tone, string> = {
  neutral: 'bg-surface-sunken text-ink border-control-border',
  success: 'bg-success-subtle text-success-strong border-success-strong',
  warning: 'bg-warning-subtle text-warning-strong border-warning-strong',
  danger: 'bg-danger-subtle text-danger-strong border-danger-strong',
}

const toneDots: Record<Tone, string> = {
  neutral: 'bg-faint',
  success: 'bg-success-strong',
  warning: 'bg-warning-strong',
  danger: 'bg-danger-strong',
}

/**
 * An asset's lifecycle status.
 *
 * The **tone comes from the server** (`AssetStatus::tone()`), so the mapping
 * from state to colour is decided once and cannot drift between the badge, the
 * dashboard tiles and the timeline.
 *
 * Never colour-only: the written label carries the meaning, the dot is
 * decorative, and the border gives the wash a defined edge at 7:1 contrast for
 * readers who perceive little of the fill.
 */
export function AssetStatusBadge({
  label,
  tone = 'neutral',
  className,
}: {
  label: string
  tone?: Tone
  className?: string
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-full border-2 px-3 py-1 text-xs font-semibold',
        toneStyles[tone],
        className,
      )}
    >
      <span className={cn('size-2.5 rounded-full', toneDots[tone])} aria-hidden="true" />
      {label}
    </span>
  )
}
