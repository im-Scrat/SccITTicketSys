import { cn } from '@/lib/cn'
import {
  PC_STATUS,
  PC_STATUS_TONE_CLASSES,
  isPcStatus,
  pcStatusLabel,
  type PcStatusCategory,
} from '@/lib/pcStatus'

interface PcStatusBadgeProps {
  /**
   * One of the three repair statuses, or `unassigned` for a PC with no room.
   * An unknown value (e.g. a stale cache) renders neutrally with its raw label
   * rather than throwing.
   */
  status: PcStatusCategory | string
  size?: 'sm' | 'md'
  className?: string
}

/**
 * Phase 2.11 — the PC status badge used on every screen (SDD DD-100).
 *
 * Wash + same-hue border + strong text + an icon, so the status survives colour
 * blindness and grayscale: the label carries the meaning, the icon gives each
 * status its own shape, and the hue is only the fastest of the three signals.
 */
export function PcStatusBadge({ status, size = 'sm', className }: PcStatusBadgeProps) {
  const known = status === 'unassigned' || isPcStatus(status)
  const presentation = known ? PC_STATUS[status as PcStatusCategory] : null
  const tone = PC_STATUS_TONE_CLASSES[presentation?.tone ?? 'neutral']
  const Icon = presentation?.icon

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border font-medium whitespace-nowrap',
        size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-sm',
        tone.wash,
        tone.border,
        tone.text,
        className,
      )}
    >
      {Icon && (
        <Icon
          aria-hidden="true"
          className={size === 'sm' ? 'size-3.5' : 'size-4'}
          strokeWidth={2.25}
        />
      )}
      {presentation?.label ?? pcStatusLabel(status)}
    </span>
  )
}
